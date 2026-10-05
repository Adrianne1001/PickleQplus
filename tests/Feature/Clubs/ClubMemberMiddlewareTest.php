<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Services\ClubService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

/**
 * The first Livewire snapshot (JSON string) of a rendered page.
 */
function firstSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
}

/**
 * Call a component action over a real /livewire/update request.
 */
function callLivewireAction(mixed $test, string $snapshot, string $method): mixed
{
    return $test->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]],
    ], ['X-Livewire' => '1']);
}

test('a removed member cannot call actions on an already-mounted component', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    $this->actingAs($staff);
    $snapshot = firstSnapshot($this->get(route('clubs.players.index', $club))->assertOk()->getContent());
    expect($snapshot)->not->toBe('');

    // Sanity: while still a member the action works.
    callLivewireAction($this, $snapshot, 'startAdd')->assertOk();

    app(ClubService::class)->removeMember($club, $staff);

    callLivewireAction($this, $snapshot, 'startAdd')->assertNotFound();
});

test('the persistent middleware itself rejects a removed member on an action without a policy check', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    $this->actingAs($staff);
    $snapshot = firstSnapshot($this->get(route('clubs.players.index', $club))->assertOk()->getContent());

    // Only a property update (search), which has no policy check of its own.
    $searchUpdate = fn () => $this->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['search' => 'zed'],
            'calls' => [],
        ]],
    ], ['X-Livewire' => '1']);

    // Livewire memoizes applied persistent middleware per route in a singleton;
    // clear it so each update really runs EnsureClubMember.
    $resetMemo = function (): void {
        $memo = new ReflectionProperty(PersistentMiddleware::class, 'middlewareAppliedFor');
        $memo->setValue(app(PersistentMiddleware::class), []);
    };

    // Positive control: while still a member the same update succeeds.
    $resetMemo();
    $searchUpdate()->assertOk();

    app(ClubService::class)->removeMember($club, $staff);

    $resetMemo();
    $searchUpdate()->assertNotFound();
});

test('Club::current is set inside Livewire update requests', function () {
    [$user, $club] = memberOf();

    $this->actingAs($user);
    $snapshot = firstSnapshot($this->get(route('clubs.players.index', $club))->getContent());

    app()->forgetInstance(Club::CONTAINER_KEY);
    expect(Club::current())->toBeNull();

    callLivewireAction($this, $snapshot, 'startAdd')->assertOk();

    expect(Club::current()?->is($club))->toBeTrue();
});

test('the middleware does not write current_club_id when it already matches', function () {
    [$user, $club] = memberOf();
    $user->forceFill(['current_club_id' => $club->id])->save();

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('clubs.show', $club))->assertOk();
    $writes = collect(DB::getQueryLog())->filter(fn (array $q): bool => preg_match('/^update ["`]users["`]/i', $q['query']) === 1);
    DB::disableQueryLog();

    expect($writes)->toHaveCount(0);
});

test('visiting a club stores it as the current club', function () {
    [$user, $club] = memberOf();

    $this->actingAs($user)->get(route('clubs.show', $club))->assertOk();

    expect($user->fresh()->current_club_id)->toBe($club->id);
});

test('role lookups are memoized per instance and refreshed after membership changes', function () {
    [$owner, $club] = memberOf();
    $staff = User::factory()->create();
    $service = app(ClubService::class);

    expect($staff->roleIn($club))->toBeNull();
    $service->addMember($club, $staff, ClubRole::Staff);
    expect($staff->roleIn($club))->toBe(ClubRole::Staff);

    DB::enableQueryLog();
    $staff->roleIn($club);
    $staff->roleIn($club);
    expect(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();

    $service->changeRole($club, $staff, ClubRole::Owner);
    expect($staff->roleIn($club))->toBe(ClubRole::Owner);

    $service->removeMember($club, $staff);
    expect($staff->roleIn($club))->toBeNull();
});

test('promotion to owner is not blocked by the owned-clubs cap', function () {
    config(['pickleq.max_owned_clubs' => 1]);
    $user = User::factory()->create();
    Club::factory()->withOwner($user)->create();
    $other = Club::factory()->withOwner()->withStaff($user)->create();
    $service = app(ClubService::class);

    $service->changeRole($other, $user, ClubRole::Owner);
    expect($user->roleIn($other))->toBe(ClubRole::Owner);

    $invited = Club::factory()->withOwner()->create();
    $service->addMember($invited, $user, ClubRole::Owner);
    expect($user->roleIn($invited))->toBe(ClubRole::Owner);

    // Creating a further club is blocked, and the message says "create".
    expect(fn () => $service->create($user, ['name' => 'One More']))
        ->toThrow(ValidationException::class, 'cannot create another');
});
