<?php

use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\PlayerService;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function playersPage(User $user, Club $club)
{
    return Livewire::actingAs($user)->test('pages::clubs.players', ['club' => $club]);
}

test('the roster renders for members, staff included', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();
    Player::factory()->for($club)->rated(4.2, 'ABC123')->create(['name' => 'Rated Rae']);

    $this->actingAs($staff)->get(route('clubs.players.index', $club))
        ->assertOk()
        ->assertSee('Rated Rae')
        ->assertSee('ABC123')
        ->assertSee('4.20')
        ->assertSee('DUPR')
        ->assertSee('data-test="import-csv-button"', false);
});

test('members can create players with DUPR rating deriving stars', function () {
    [$user, $club] = memberOf();

    playersPage($user, $club)
        ->call('startAdd')
        ->set('name', 'Ana')
        ->set('dupr_id', ' 8dplx8 ')
        ->set('dupr_rating', '4.250')
        ->assertSet('rating_source', 'dupr')
        ->call('save')
        ->assertHasNoErrors();

    $player = $club->players()->firstOrFail();
    expect($player->dupr_id)->toBe('8DPLX8')
        ->and($player->rating_source)->toBe(RatingSource::Dupr)
        ->and($player->stars)->toBe(5)
        ->and($player->active)->toBeTrue();
});

test('the form previews stars live with the club bands', function () {
    [$user, $club] = memberOf();
    $club->forceFill(['star_bands' => [3.0, 3.1, 3.2, 3.3, 3.4]])->save();

    playersPage($user, $club)
        ->call('startAdd')
        ->set('dupr_rating', '3.25')
        ->assertSeeHtml('data-test="stars-preview"')
        ->assertSeeHtml('aria-label="4 stars"')
        ->set('dupr_rating', '9')
        ->assertSee('Enter a rating between 2.0 and 8.0.');
});

test('unrated players require manual stars', function () {
    [$user, $club] = memberOf();

    playersPage($user, $club)
        ->call('startAdd')
        ->set('name', 'Bo')
        ->call('save')
        ->assertHasErrors('stars')
        ->set('stars', '3')
        ->call('save')
        ->assertHasNoErrors();

    $player = $club->players()->firstOrFail();
    expect($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->stars)->toBe(3)
        ->and($player->dupr_id)->toBeNull();
});

test('a rated player can be switched to manual stars and back', function () {
    [$user, $club] = memberOf();

    $page = playersPage($user, $club)
        ->call('startAdd')
        ->set('name', 'Cy')
        ->set('dupr_rating', '3.0')
        ->set('rating_source', 'manual')
        ->set('stars', '6')
        ->call('save')
        ->assertHasNoErrors();

    $player = $club->players()->firstOrFail();
    expect($player->rating_source)->toBe(RatingSource::Manual)->and($player->stars)->toBe(6);

    $page->call('startEdit', $player->id)
        ->assertSet('rating_source', 'manual')
        ->set('rating_source', 'dupr')
        ->call('save')
        ->assertHasNoErrors();

    expect($player->fresh()->stars)->toBe(3)->and($player->fresh()->rating_source)->toBe(RatingSource::Dupr);
});

test('editing prefills the form and saves changes', function () {
    [$user, $club] = memberOf();
    $player = Player::factory()->for($club)->rated(3.75, 'ABC123')->create(['name' => 'Old']);

    playersPage($user, $club)
        ->call('startEdit', $player->id)
        ->assertSet('name', 'Old')
        ->assertSet('dupr_id', 'ABC123')
        ->assertSet('dupr_rating', '3.75')
        ->assertSet('rating_source', 'dupr')
        ->set('name', 'New')
        ->call('save')
        ->assertHasNoErrors();

    expect($player->fresh()->name)->toBe('New')->and($player->fresh()->stars)->toBe(4);
});

test('removing the rating forces manual and keeps stars', function () {
    [$user, $club] = memberOf();
    $player = Player::factory()->for($club)->rated(4.6)->create();

    playersPage($user, $club)
        ->call('startEdit', $player->id)
        ->set('dupr_rating', '')
        ->assertSet('rating_source', 'manual')
        ->call('save')
        ->assertHasNoErrors();

    $player->refresh();
    expect($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->dupr_rating)->toBeNull()
        ->and($player->stars)->toBe(6);
});

test('invalid DUPR ids and ratings are rejected', function (array $props, string $errorKey) {
    [$user, $club] = memberOf();

    $page = playersPage($user, $club)->call('startAdd')->set('name', 'X')->set('stars', '2');
    foreach ($props as $key => $value) {
        $page->set($key, $value);
    }
    $page->call('save')->assertHasErrors($errorKey);

    expect($club->players()->count())->toBe(0);
})->with([
    'too short' => [['dupr_id' => 'ABC12'], 'dupr_id'],
    'too long' => [['dupr_id' => 'ABC1234'], 'dupr_id'],
    'symbols' => [['dupr_id' => 'ABC-12'], 'dupr_id'],
    'rating too low' => [['dupr_rating' => '1.9'], 'dupr_rating'],
    'rating too high' => [['dupr_rating' => '8.5'], 'dupr_rating'],
    'too many decimals' => [['dupr_rating' => '3.1234'], 'dupr_rating'],
    'stars too high' => [['stars' => '7'], 'stars'],
    'stars zero' => [['stars' => '0'], 'stars'],
]);

test('the name is required', function () {
    [$user, $club] = memberOf();

    playersPage($user, $club)->call('startAdd')->set('stars', '2')->call('save')->assertHasErrors('name');
});

test('DUPR id is unique per club after normalisation', function () {
    [$user, $club] = memberOf();
    Player::factory()->for($club)->create(['dupr_id' => 'ABC123']);

    playersPage($user, $club)->call('startAdd')
        ->set('name', 'Dup')->set('dupr_id', ' abc123')->set('stars', '2')
        ->call('save')
        ->assertHasErrors('dupr_id');
    expect($club->players()->count())->toBe(1);
});

test('the same DUPR id is allowed in different clubs', function () {
    [$user, $club] = memberOf();
    $other = Club::factory()->withOwner()->create();
    Player::factory()->for($other)->create(['dupr_id' => 'ABC123']);

    playersPage($user, $club)->call('startAdd')
        ->set('name', 'Same')->set('dupr_id', 'abc123')->set('stars', '2')
        ->call('save')
        ->assertHasNoErrors();
    expect($club->players()->where('dupr_id', 'ABC123')->count())->toBe(1);
});

test('updating a player keeps its own DUPR id valid', function () {
    [$user, $club] = memberOf();
    $player = Player::factory()->for($club)->create(['dupr_id' => 'ABC123']);

    playersPage($user, $club)->call('startEdit', $player->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();
    expect($player->fresh()->name)->toBe('Renamed');
});

test('players are deactivated and reactivated, never deleted', function () {
    [$user, $club] = memberOf();
    $player = Player::factory()->for($club)->create();

    $page = playersPage($user, $club)->call('deactivate', $player->id)->assertHasNoErrors();
    expect($player->fresh()->active)->toBeFalse();

    $page->call('reactivate', $player->id);
    expect($player->fresh()->active)->toBeTrue()
        ->and(method_exists($page->instance(), 'delete'))->toBeFalse();
});

test('staff can manage players', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    playersPage($staff, $club)->call('startAdd')
        ->set('name', 'S')->set('stars', '2')
        ->call('save')
        ->assertHasNoErrors();
    expect($club->players()->count())->toBe(1);
});

test('search filters by name or DUPR id', function () {
    [$user, $club] = memberOf();
    Player::factory()->for($club)->create(['name' => 'Alice Alpha']);
    Player::factory()->for($club)->rated(3.5, 'ZZ9999')->create(['name' => 'Bob Beta']);

    playersPage($user, $club)
        ->set('search', 'alice')
        ->assertSee('Alice Alpha')
        ->assertDontSee('Bob Beta')
        ->set('search', 'zz99')
        ->assertSee('Bob Beta')
        ->assertDontSee('Alice Alpha')
        ->set('search', 'nobody')
        ->assertSee('No players match your filters.');
});

test('search treats LIKE wildcards literally', function () {
    [$user, $club] = memberOf();
    Player::factory()->for($club)->create(['name' => 'Alice']);

    playersPage($user, $club)->set('search', '%')->assertDontSee('Alice');
});

test('status filter shows active, inactive or all players', function () {
    [$user, $club] = memberOf();
    Player::factory()->for($club)->create(['name' => 'Activeo']);
    Player::factory()->for($club)->inactive()->create(['name' => 'Inactivo']);

    playersPage($user, $club)
        ->assertSee('Activeo')->assertDontSee('Inactivo')
        ->set('status', 'inactive')->assertSee('Inactivo')->assertDontSee('Activeo')
        ->set('status', 'all')->assertSee('Inactivo')->assertSee('Activeo');
});

test('the roster is paginated', function () {
    [$user, $club] = memberOf();
    foreach (range(1, 17) as $i) {
        Player::factory()->for($club)->create(['name' => sprintf('Player %02d', $i)]);
    }

    playersPage($user, $club)
        ->assertSee('Player 15')
        ->assertDontSee('Player 16')
        ->call('gotoPage', 2)
        ->assertSee('Player 16')
        ->assertDontSee('Player 01');
});

test('only this club players are listed', function () {
    [$user, $club] = memberOf();
    $other = Club::factory()->withOwner()->create();
    Player::factory()->for($other)->create(['name' => 'Foreigner']);

    playersPage($user, $club)->assertDontSee('Foreigner');
});

test('a member of club A cannot touch club B players', function () {
    [$user, $clubA] = memberOf();
    $clubB = Club::factory()->withOwner()->create();
    $foreign = Player::factory()->for($clubB)->create(['name' => 'Foreign']);

    $this->actingAs($user);
    // Through the foreign club slug: membership middleware gives 404.
    $this->get(route('clubs.players.index', $clubB))->assertNotFound();

    // Through own club component with a foreign player id: scoped lookup gives 404.
    playersPage($user, $clubA)->call('startEdit', $foreign->id)->assertNotFound();
    playersPage($user, $clubA)->call('deactivate', $foreign->id)->assertNotFound();
    playersPage($user, $clubA)->call('reactivate', $foreign->id)->assertNotFound();

    // Saving an edit whose id was tampered with cannot happen: the id is locked.
    $page = playersPage($user, $clubA)->call('startAdd');
    expect(fn () => $page->set('editingId', $foreign->id))
        ->toThrow(Exception::class, 'Cannot update locked property');
    expect(fn () => $page->set('club', $clubB->id))
        ->toThrow(Exception::class, 'Cannot update locked property');

    expect($foreign->fresh()->name)->toBe('Foreign')
        ->and($foreign->fresh()->active)->toBeTrue()
        ->and($clubB->players()->count())->toBe(1);
});

test('the player policy denies non-members as not found', function () {
    $club = Club::factory()->withOwner()->create();
    $player = Player::factory()->for($club)->create();
    $outsider = User::factory()->create();

    expect($outsider->can('update', $player))->toBeFalse();
    $response = Gate::forUser($outsider)->inspect('update', $player);
    expect($response->status())->toBe(404);
});

test('player service handles create and partial update directly', function () {
    $club = Club::factory()->create();
    $service = app(PlayerService::class);

    $player = $service->create($club, ['name' => 'Svc', 'dupr_id' => 'zz9999', 'dupr_rating' => 3.5]);
    expect($player->dupr_id)->toBe('ZZ9999')->and($player->stars)->toBe(4);

    $service->update($player, ['dupr_rating' => 2.4]);
    expect($player->fresh()->stars)->toBe(1);
});
