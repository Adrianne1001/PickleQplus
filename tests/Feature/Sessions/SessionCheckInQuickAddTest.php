<?php

use App\Enums\LateArrivalPolicy;
use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\SessionFormService;
use App\Services\SessionListService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function qaCheckIns(): CheckInService
{
    return app(CheckInService::class);
}

function qaList(): SessionListService
{
    return app(SessionListService::class);
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

test('quick-add creates a manual player and puts them on the list without checking them in', function () {
    $session = PlaySession::factory()->create();

    $player = qaList()->addNewPlayer($session, ['name' => '  Sam Lee ', 'stars' => 3, 'dupr_id' => 'ab12cd', 'gender' => 'woman']);

    expect($player->name)->toBe('Sam Lee')
        ->and($player->club_id)->toBe($session->club_id)
        ->and($player->stars)->toBe(3)
        ->and($player->stars_pending)->toBeFalse()
        ->and($player->dupr_id)->toBe('AB12CD')
        ->and($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->self_registered_session_id)->toBeNull()
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->where('player_id', $player->id)->exists())->toBeTrue()
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('quick-add without stars lists the player at 3 stars with skill not set', function () {
    $session = PlaySession::factory()->create();

    $player = qaList()->addNewPlayer($session, ['name' => 'No Stars', 'stars' => '']);

    expect($player->stars)->toBe(3)->and($player->stars_pending)->toBeTrue()
        ->and($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->fresh()->stars_pending)->toBeTrue();
});

test('quick-add on a live session lists the player, and checking them in later gives late-arrival credit', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $other = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $other->id, 'games_played' => 4, 'status' => SessionPlayerStatus::Waiting]);

    $player = qaList()->addNewPlayer($session, ['name' => 'Late Larry', 'stars' => 2]);

    expect(SessionPlayer::query()->where('player_id', $player->id)->exists())->toBeFalse();

    $entry = qaList()->checkIn($session, $player->id);

    expect($entry->games_credit)->toBe(4);
});

test('quick-add is rejected on an ended session and creates nothing', function () {
    $session = PlaySession::factory()->ended()->create();

    $errors = qaErrors(fn () => qaList()->addNewPlayer($session, ['name' => 'Nope', 'stars' => 2]));

    expect($errors)->toHaveKey('session')
        ->and(Player::query()->count())->toBe(0);
});

test('quick-add rejects a name that differs only in case and creates nothing', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->create(['name' => 'Ann Bee']);

    $errors = qaErrors(fn () => qaList()->addNewPlayer($session, ['name' => 'ANN BEE', 'stars' => 2]));

    expect($errors)->toHaveKey('name')
        ->and(Player::query()->count())->toBe(1)
        ->and(SessionListPlayer::query()->count())->toBe(0);
});

test('quick-add validates stars, dupr id and gender', function () {
    $session = PlaySession::factory()->create();

    expect(qaErrors(fn () => qaList()->addNewPlayer($session, ['name' => 'A B', 'stars' => 9])))->toHaveKey('stars')
        ->and(qaErrors(fn () => qaList()->addNewPlayer($session, ['name' => 'A B', 'stars' => 2, 'gender' => 'x'])))->toHaveKey('gender')
        ->and(qaErrors(fn () => qaList()->addNewPlayer($session, ['name' => 'A B', 'stars' => 2, 'dupr_id' => '!!'])))->toHaveKey('dupr_id')
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

test('form save creates a session and lists the picks and new players without checking anyone in', function () {
    $club = Club::factory()->create();
    $picked = Player::factory()->for($club)->create();

    $session = qaForm()->save($club, null, qaData(), [$picked->id], [['name' => 'New One', 'stars' => 4]]);

    expect($session->club_id)->toBe($club->id)
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->count())->toBe(2)
        ->and(SessionPlayer::query()->count())->toBe(0)
        ->and(Player::query()->where('name', 'New One')->exists())->toBeTrue();
});

test('form save lists picks with their import source and the saver', function () {
    $club = Club::factory()->create();
    $a = Player::factory()->for($club)->create();
    $b = Player::factory()->for($club)->create();
    $user = User::factory()->create();

    $session = qaForm()->save($club, null, qaData(), [$a->id, ['id' => $b->id, 'source' => 'reclub']], [['name' => 'From Paste', 'source' => 'paste', 'dupr_rating' => '4.2']], $user);

    $rows = SessionListPlayer::query()->where('play_session_id', $session->id)->get()->keyBy('player_id');
    $new = Player::query()->where('name', 'From Paste')->firstOrFail();
    expect($rows[$a->id]->source->value)->toBe('manual')
        ->and($rows[$b->id]->source->value)->toBe('reclub')
        ->and($rows[$new->id]->source->value)->toBe('paste')
        ->and($rows[$a->id]->added_by_user_id)->toBe($user->id)
        ->and($new->rating_source)->toBe(RatingSource::Dupr)
        ->and($new->stars_pending)->toBeFalse();
});

test('form save sets, keeps and clears the session password', function () {
    $club = Club::factory()->create();

    $session = qaForm()->save($club, null, qaData() + ['checkin_password' => ' Court7 ']);
    expect($session->fresh()->checkin_password)->toBe('Court7');

    qaForm()->save($club, $session, ['name' => 'Renamed']);
    expect($session->fresh()->checkin_password)->toBe('Court7');

    expect(qaErrors(fn () => qaForm()->save($club, $session, ['checkin_password' => 'abc'])))->toHaveKey('password')
        ->and($session->fresh()->checkin_password)->toBe('Court7');

    qaForm()->save($club, $session, ['checkin_password' => '']);
    expect($session->fresh()->checkin_password)->toBeNull();
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
        ->and(SessionListPlayer::query()->count())->toBe(0);
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
        ->and(SessionListPlayer::query()->count())->toBe(0);
});

test('form save reports new player stars errors by index', function () {
    $club = Club::factory()->create();

    $errors = qaErrors(fn () => qaForm()->save($club, null, qaData(), [], [['name' => 'Bad Stars', 'stars' => 9]]));

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

test('editing a live session lists the players and checks in nobody', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $other = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $other->id, 'games_played' => 3, 'status' => SessionPlayerStatus::Waiting]);
    $picked = Player::factory()->for($club)->create();

    qaForm()->save($club, $session, ['name' => 'Live edit'], [$picked->id], [['name' => 'Brand New', 'stars' => 2]]);

    expect(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(1)
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->count())->toBe(3)
        ->and($session->fresh()->name)->toBe('Live edit');

    // Check In from the list gives the late-arrival credit.
    expect(qaList()->checkIn($session, $picked->id)->games_credit)->toBe(3);
});

test('form save is not allowed on an ended session', function () {
    $session = PlaySession::factory()->ended()->create();

    $errors = qaErrors(fn () => qaForm()->save($session->club, $session, ['name' => 'X'], [], [['name' => 'Late', 'stars' => 2]]));

    expect($errors)->toHaveKey('status')->and(Player::query()->where('name', 'Late')->exists())->toBeFalse();
});

test('a pick who is in another live session can be listed, but Check In refuses them', function () {
    $club = Club::factory()->create(['allow_concurrent_sessions' => true]);
    $session = PlaySession::factory()->for($club)->live()->create(['name' => 'Original']);
    $other = PlaySession::factory()->for($club)->live()->create();
    $busy = Player::factory()->for($club)->create(['name' => 'Busy Bob']);
    SessionPlayer::factory()->create(['play_session_id' => $other->id, 'player_id' => $busy->id, 'status' => SessionPlayerStatus::Waiting]);

    qaForm()->save($club, $session, ['name' => 'Changed'], [$busy->id]);

    expect($session->fresh()->name)->toBe('Changed')
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->where('player_id', $busy->id)->exists())->toBeTrue()
        ->and(qaErrors(fn () => qaList()->checkIn($session, $busy->id)))->toHaveKey('player')
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(0);
});

test('accent differences count as the same name like the unique index', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'José']);

    expect(qaCheckIns()->findPlayerByName($club, 'josé'))->not->toBeNull()
        ->and(Player::nameTaken($club->id, 'JOSÉ'))->toBeTrue();
});
