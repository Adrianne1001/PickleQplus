<?php

use App\Enums\LateArrivalPolicy;
use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
use App\Services\SessionFormService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function qaCheckIns(): CheckInService
{
    return app(CheckInService::class);
}

function qaForm(): SessionFormService
{
    return app(SessionFormService::class);
}

/** @return array<string, mixed> */
function qaData(string $name = 'Friday'): array
{
    return ['name' => $name, 'date' => now()->toDateString(), 'courts' => 2];
}

/** @return array<string, list<string>> */
function qaErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('quick-add creates a manual player and checks them in to a draft', function () {
    $session = PlaySession::factory()->create();

    $entry = qaCheckIns()->addNewPlayer($session, ['name' => '  Sam Lee ', 'stars' => 3, 'dupr_id' => 'ab12cd', 'gender' => 'woman']);

    $player = Player::query()->findOrFail($entry->player_id);
    expect($player->name)->toBe('Sam Lee')
        ->and($player->club_id)->toBe($session->club_id)
        ->and($player->stars)->toBe(3)
        ->and($player->dupr_id)->toBe('AB12CD')
        ->and($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->self_registered_session_id)->toBeNull()
        ->and($entry->status)->toBe(SessionPlayerStatus::Waiting)
        ->and($entry->games_credit)->toBe(0);
});

test('quick-add on a live session gives late-arrival credit', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $other = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $other->id, 'games_played' => 4, 'status' => SessionPlayerStatus::Waiting]);

    $entry = qaCheckIns()->addNewPlayer($session, ['name' => 'Late Larry', 'stars' => 2]);

    expect($entry->games_credit)->toBe(4);
});

test('quick-add is rejected on an ended session and creates nothing', function () {
    $session = PlaySession::factory()->ended()->create();

    $errors = qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'Nope', 'stars' => 2]));

    expect($errors)->toHaveKey('session')
        ->and(Player::query()->count())->toBe(0);
});

test('quick-add rejects a name that differs only in case and creates nothing', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->create(['name' => 'Ann Bee']);

    $errors = qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'ANN BEE', 'stars' => 2]));

    expect($errors)->toHaveKey('name')
        ->and(Player::query()->count())->toBe(1)
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('quick-add validates stars, dupr id and gender', function () {
    $session = PlaySession::factory()->create();

    expect(qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'A B'])))->toHaveKey('stars')
        ->and(qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'A B', 'stars' => 9])))->toHaveKey('stars')
        ->and(qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'A B', 'stars' => 2, 'gender' => 'x'])))->toHaveKey('gender')
        ->and(qaErrors(fn () => qaCheckIns()->addNewPlayer($session, ['name' => 'A B', 'stars' => 2, 'dupr_id' => '!!'])))->toHaveKey('dupr_id')
        ->and(Player::query()->count())->toBe(0);
});

test('name lookup finds active, deactivated and missing names within the club', function () {
    $club = Club::factory()->create();
    $active = Player::factory()->for($club)->create(['name' => 'Zed Active']);
    Player::factory()->for($club)->inactive()->create(['name' => 'Yan Off']);
    Player::factory()->create(['name' => 'Elsewhere']);

    expect(qaCheckIns()->findPlayerByName($club, ' zed ACTIVE ')?->is($active))->toBeTrue()
        ->and(qaCheckIns()->findPlayerByName($club, 'yan off')?->active)->toBeFalse()
        ->and(qaCheckIns()->findPlayerByName($club, 'Elsewhere'))->toBeNull()
        ->and(qaCheckIns()->findPlayerByName($club, 'Nobody'))->toBeNull();
});

test('form save creates a session with picks and new players', function () {
    $club = Club::factory()->create();
    $picked = Player::factory()->for($club)->create();

    $session = qaForm()->save($club, null, qaData(), [$picked->id], [['name' => 'New One', 'stars' => 4]]);

    expect($session->club_id)->toBe($club->id)
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(2)
        ->and(Player::query()->where('name', 'New One')->exists())->toBeTrue();
});

test('form save with empty lists behaves like before', function () {
    $club = Club::factory()->create();

    $session = qaForm()->save($club, null, qaData('Solo'));
    $updated = qaForm()->save($club, $session, ['name' => 'Renamed']);

    expect($updated->name)->toBe('Renamed')->and(SessionPlayer::query()->count())->toBe(0);
});

test('form save rolls back everything when a pick is from another club', function () {
    $club = Club::factory()->create();
    $mine = Player::factory()->for($club)->create();
    $foreign = Player::factory()->create();
    Event::fake([PlaySessionChanged::class]);

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [$mine->id, $foreign->id], [['name' => 'New One', 'stars' => 4]]));

    Event::assertNotDispatched(PlaySessionChanged::class);
    expect($errors)->toHaveKey('players')
        ->and(PlaySession::query()->count())->toBe(0)
        ->and(Player::query()->where('name', 'New One')->exists())->toBeFalse()
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('form save rolls back when a pick is inactive and names the player', function () {
    $club = Club::factory()->create();
    $off = Player::factory()->for($club)->inactive()->create(['name' => 'Gone Gary']);

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [$off->id], [['name' => 'New One', 'stars' => 4]]));

    expect($errors['players'][0])->toContain('Gone Gary')
        ->and(PlaySession::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(1);
});

test('form save rolls back when a new player name is taken', function () {
    $club = Club::factory()->create();
    $picked = Player::factory()->for($club)->create();
    Player::factory()->for($club)->create(['name' => 'Taken Tom']);

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [$picked->id], [
        ['name' => 'Fresh Fay', 'stars' => 3],
        ['name' => 'taken tom', 'stars' => 3],
    ]));

    expect($errors)->toHaveKey('new_players.1.name')
        ->and(PlaySession::query()->count())->toBe(0)
        ->and(Player::query()->where('name', 'Fresh Fay')->exists())->toBeFalse()
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('form save reports new player stars errors by index', function () {
    $club = Club::factory()->create();

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [], [['name' => 'No Stars']]));

    expect($errors)->toHaveKey('new_players.0.stars')->and(PlaySession::query()->count())->toBe(0);
});

test('form save rejects duplicate pending names case-insensitively', function () {
    $club = Club::factory()->create();

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [], [
        ['name' => 'Dup Dan', 'stars' => 3],
        ['name' => ' dup dan', 'stars' => 4],
    ]));

    expect($errors)->toHaveKey('new_players.1.name')->and(PlaySession::query()->count())->toBe(0);
});

test('editing a live session adds check-ins with credit', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $other = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $other->id, 'games_played' => 3, 'status' => SessionPlayerStatus::Waiting]);
    $picked = Player::factory()->for($club)->create();

    qaForm()->save($club, $session, ['name' => 'Live edit'], [$picked->id], [['name' => 'Brand New', 'stars' => 2]]);

    $entries = SessionPlayer::query()->where('play_session_id', $session->id)->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->firstWhere('player_id', $picked->id)->games_credit)->toBe(3)
        ->and($session->fresh()->name)->toBe('Live edit');
});

test('form save is not allowed on an ended session', function () {
    $session = PlaySession::factory()->ended()->create();

    $errors = qaErrors(fn () => qaForm()->save($session->club, $session, ['name' => 'X'], [], [['name' => 'Late', 'stars' => 2]]));

    expect($errors)->toHaveKey('status')->and(Player::query()->where('name', 'Late')->exists())->toBeFalse();
});

test('editing a live session rolls back when a pick is already in another live session', function () {
    $club = Club::factory()->create(['allow_concurrent_sessions' => true]);
    $session = PlaySession::factory()->for($club)->live()->create(['name' => 'Original']);
    $other = PlaySession::factory()->for($club)->live()->create();
    $fine = Player::factory()->for($club)->create();
    $busy = Player::factory()->for($club)->create(['name' => 'Busy Bob']);
    SessionPlayer::factory()->create(['play_session_id' => $other->id, 'player_id' => $busy->id, 'status' => SessionPlayerStatus::Waiting]);

    $errors = qaErrors(fn () => qaForm()->save($club, $session, ['name' => 'Changed'], [$fine->id, $busy->id], [['name' => 'Brand New', 'stars' => 2]]));

    expect($errors['players'][0])->toStartWith('Busy Bob: ')
        ->and($session->fresh()->name)->toBe('Original')
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(0)
        ->and(Player::query()->where('name', 'Brand New')->exists())->toBeFalse();
});

test('accent differences count as the same name like the unique index', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'José']);

    expect(qaCheckIns()->findPlayerByName($club, 'josé'))->not->toBeNull()
        ->and(Player::nameTaken($club->id, 'JOSÉ'))->toBeTrue();
});
