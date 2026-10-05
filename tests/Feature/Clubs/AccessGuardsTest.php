<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\ClubService;
use Livewire\Livewire;

/**
 * Mount a club page over HTTP and return its Livewire snapshot, so tests can
 * replay actions through a real /livewire/update request later.
 */
function guardSnapshot(mixed $test, string $url): string
{
    $html = $test->get($url)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

/**
 * @param  list<mixed>  $params
 */
function guardCall(mixed $test, string $snapshot, string $method, array $params = []): mixed
{
    return $test->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]],
    ], ['X-Livewire' => '1']);
}

test('club-scoped properties are locked on every club component', function (string $component, string $property) {
    $user = User::factory()->create();
    $mine = Club::factory()->withOwner($user)->create();
    $other = Club::factory()->withOwner()->create();

    $page = Livewire::actingAs($user)->test($component, ['club' => $mine]);

    expect(fn () => $page->set($property, $property === 'club' ? $other->id : 1))
        ->toThrow(Exception::class, 'Cannot update locked property');
})->with([
    'members club' => ['pages::clubs.members', 'club'],
    'players club' => ['pages::clubs.players', 'club'],
    'players editingId' => ['pages::clubs.players', 'editingId'],
    'players importResult' => ['pages::clubs.players', 'importResult'],
]);

test('a user who lost verification mid-session is blocked from component actions', function () {
    [$user, $club] = memberOf();

    $this->actingAs($user);
    $snapshot = guardSnapshot($this, route('clubs.players.index', $club));
    // No prior update call on purpose: Livewire memoizes persistent middleware per
    // route for the lifetime of the app instance, which only matters in tests.
    $user->forceFill(['email_verified_at' => null])->save();

    // Persistent EnsureEmailIsVerified must stop the update request.
    guardCall($this, $snapshot, 'startAdd')->assertForbidden();
});

test('a removed member is refused on import, member and invite actions', function () {
    $owner = User::factory()->create();
    $second = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    app(ClubService::class)->addMember($club, $second, ClubRole::Owner);

    $this->actingAs($second);
    $players = guardSnapshot($this, route('clubs.players.index', $club));
    $members = guardSnapshot($this, route('clubs.members', $club));

    app(ClubService::class)->removeMember($club, $second);

    guardCall($this, $players, 'startImport')->assertNotFound();
    guardCall($this, $players, 'commitImport')->assertNotFound();
    guardCall($this, $members, 'invite')->assertNotFound();
    guardCall($this, $members, 'changeRole', [$owner->id, 'staff'])->assertNotFound();

    expect($owner->roleIn($club))->toBe(ClubRole::Owner)
        ->and($club->invitations()->count())->toBe(0);
});

test('an owner demoted after mounting settings is refused owner-only actions', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $club = Club::factory()->withOwner($a)->create();
    app(ClubService::class)->addMember($club, $b, ClubRole::Owner);

    $this->actingAs($b);
    $snapshot = guardSnapshot($this, route('clubs.settings', $club));

    app(ClubService::class)->changeRole($club, $b, ClubRole::Staff);

    guardCall($this, $snapshot, 'saveDetails')->assertForbidden();
    guardCall($this, $snapshot, 'saveStarBands')->assertForbidden();
    guardCall($this, $snapshot, 'resetStarBands')->assertForbidden();
    guardCall($this, $snapshot, 'deleteClub')->assertForbidden();

    expect(Club::query()->whereKey($club->id)->exists())->toBeTrue();
});

test('staff are refused every owner-only members action', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $owner->id, 'staff')->assertForbidden();

    expect($owner->roleIn($club))->toBe(ClubRole::Owner);
});

test('player and roster actions on another club players are not found', function () {
    [$user, $club] = memberOf();
    $foreign = Player::factory()->for(Club::factory()->withOwner()->create())->create();

    foreach (['startEdit', 'deactivate', 'reactivate'] as $action) {
        Livewire::actingAs($user)->test('pages::clubs.players', ['club' => $club])
            ->call($action, $foreign->id)->assertNotFound();
    }

    expect($foreign->fresh()?->active)->toBeTrue();
});

test('DUPR rating and id boundaries are accepted', function (string $rating, string $duprId) {
    [$user, $club] = memberOf();

    Livewire::actingAs($user)->test('pages::clubs.players', ['club' => $club])
        ->call('startAdd')
        ->set('name', 'Edge')
        ->set('dupr_id', $duprId)
        ->set('dupr_rating', $rating)
        ->call('save')
        ->assertHasNoErrors();

    $player = $club->players()->firstOrFail();
    expect($player->dupr_id)->toBe(strtoupper($duprId))
        ->and((float) $player->dupr_rating)->toBe((float) $rating);
})->with([
    'minimum rating' => ['2.000', 'abc123'],
    'maximum rating' => ['8.000', '000000'],
    'three decimals' => ['3.125', 'zzzzzz'],
]);
