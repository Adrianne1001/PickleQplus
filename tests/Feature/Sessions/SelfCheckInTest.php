<?php

use App\Enums\MatchStatus;
use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Events\SessionUpdated;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\PlayerService;
use App\Services\PlaySessionService;
use App\Services\PublicSessionView;
use App\Services\SelfCheckInService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function selfSvc(): SelfCheckInService
{
    return app(SelfCheckInService::class);
}

function selfLiveSession(?Club $club = null): PlaySession
{
    return PlaySession::factory()->live()->for($club ?? Club::factory()->create())->create();
}

function expectInvalid(Closure $fn, string $key): void
{
    try {
        $fn();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);

        return;
    }
    throw new RuntimeException('Expected ValidationException');
}

beforeEach(fn () => RateLimiter::clear('x'));

test('publicName uses nickname, else first name and last initial', function () {
    expect((new Player(['name' => 'Adrianne Basuel']))->publicName())->toBe('Adrianne B.')
        ->and((new Player(['name' => 'Mary Jane van der Berg']))->publicName())->toBe('Mary B.')
        ->and((new Player(['name' => 'Cher']))->publicName())->toBe('Cher')
        ->and((new Player(['name' => 'Adrianne Basuel', 'nickname' => 'AB']))->publicName())->toBe('AB');
});

test('nickname is unique per club case-insensitively but reusable across clubs', function () {
    $club = Club::factory()->create();
    $svc = app(PlayerService::class);
    $svc->create($club, ['name' => 'A One', 'nickname' => 'Ace', 'stars' => 3]);

    expectInvalid(fn () => $svc->create($club, ['name' => 'B Two', 'nickname' => 'aCE', 'stars' => 3]), 'nickname');
    expectInvalid(fn () => $svc->create($club, ['name' => 'B Two', 'nickname' => str_repeat('x', 21), 'stars' => 3]), 'nickname');

    $ok = $svc->create(Club::factory()->create(), ['name' => 'C Three', 'nickname' => 'Ace', 'stars' => 3]);
    $blank = $svc->create($club, ['name' => 'D Four', 'nickname' => '  ', 'stars' => 3]);
    expect($ok->nickname)->toBe('Ace')->and($blank->nickname)->toBeNull();
});

test('search needs 2 chars, matches name or nickname, caps at 10, active only, club only', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    Player::factory()->count(12)->for($club)->create(['name' => 'Sam Player']);
    Player::factory()->for($club)->create(['name' => 'Zed Quiet', 'nickname' => 'Samurai']);
    Player::factory()->for($club)->create(['name' => 'Sam Gone', 'active' => false]);
    Player::factory()->create(['name' => 'Sam Elsewhere']);

    expect(selfSvc()->search($session, (string) $session->checkin_token, 's', '1.1.1.1'))->toBe([])
        ->and(selfSvc()->search($session, (string) $session->checkin_token, 'sam', '1.1.1.1'))->toHaveCount(10)
        ->and(selfSvc()->search($session, (string) $session->checkin_token, 'samur', '1.1.1.1'))->toHaveCount(1)
        ->and(selfSvc()->search($session, (string) $session->checkin_token, 'Gone', '1.1.1.1'))->toBe([])
        ->and(selfSvc()->search($session, (string) $session->checkin_token, '%%', '1.1.1.1'))->toBe([]);
});

test('search reports who is already checked in', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    $in = Player::factory()->for($club)->create(['name' => 'Ina Here']);
    Player::factory()->for($club)->create(['name' => 'Ino Away']);
    app(CheckInService::class)->checkIn($session, $in);

    $rows = selfSvc()->search($session, (string) $session->checkin_token, 'in', '1.1.1.1');

    expect(collect($rows)->firstWhere('id', $in->public_id)['status'])->toBe('waiting')
        ->and(collect($rows)->where('status', null))->toHaveCount(1);
});

test('checkIn checks in, sets an empty nickname once and is idempotent', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    $player = Player::factory()->for($club)->create(['name' => 'Pat Lee']);

    $first = selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, 'Patty', '1.1.1.1');
    $second = selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, 'Other', '1.1.1.1');

    expect($first['result'])->toBe('checked_in')->and($first['public_name'])->toBe('Patty')
        ->and($second['result'])->toBe('already_checked_in')
        ->and($player->fresh()->nickname)->toBe('Patty')
        ->and(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(1);
});

test('checkIn rejects a taken nickname, cross-club ids, inactive players and ended sessions', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    Player::factory()->for($club)->create(['nickname' => 'Taken']);
    $mine = Player::factory()->for($club)->create();
    $foreign = Player::factory()->create();
    $inactive = Player::factory()->for($club)->create(['active' => false]);

    expectInvalid(fn () => selfSvc()->checkIn($session, (string) $session->checkin_token, $mine->public_id, 'taken', '2.2.2.2'), 'nickname');
    expectInvalid(fn () => selfSvc()->checkIn($session, (string) $session->checkin_token, $foreign->public_id, null, '2.2.2.2'), 'player');
    expectInvalid(fn () => selfSvc()->checkIn($session, (string) $session->checkin_token, $inactive->public_id, null, '2.2.2.2'), 'player');
    expect(SessionPlayer::query()->count())->toBe(0);

    $ended = PlaySession::factory()->ended()->for($club)->create();
    expectInvalid(fn () => selfSvc()->checkIn($ended, (string) $ended->checkin_token, $mine->public_id, null, '2.2.2.2'), 'session');
    expectInvalid(fn () => selfSvc()->search($ended, (string) $ended->checkin_token, 'abc', '2.2.2.2'), 'session');
});

test('checkIn fires the change event', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    $player = Player::factory()->for($club)->create();
    Event::fake([PlaySessionChanged::class]);

    selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, null, '3.3.3.3');

    Event::assertDispatched(PlaySessionChanged::class);
});

test('register creates a manual self-registered player and checks them in', function () {
    $session = selfLiveSession();

    $res = selfSvc()->register($session, (string) $session->checkin_token, ' New Person ', 'Newbie', 'ab12cd', 4, '4.4.4.4');

    $player = Player::query()->where('public_id', $res['player_id'])->firstOrFail();
    expect($res['result'])->toBe('registered')->and($res['public_name'])->toBe('Newbie')
        ->and($player->name)->toBe('New Person')
        ->and($player->dupr_id)->toBe('AB12CD')
        ->and($player->stars)->toBe(4)
        ->and($player->rating_source)->toBe(RatingSource::Manual)
        ->and($player->self_registered_at)->not->toBeNull()
        ->and($player->club_id)->toBe($session->club_id)
        ->and(SessionPlayer::query()->where('player_id', $player->id)->firstOrFail()->status)->toBe(SessionPlayerStatus::Waiting);
});

$GLOBALS['ipn'] = 1;

test('register validates input and rejects duplicate nickname or DUPR id', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    Player::factory()->for($club)->create(['nickname' => 'Dup', 'dupr_id' => 'ZZ99ZZ']);

    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'A B', 'dUP', null, 3, '5.5.5.'.$GLOBALS['ipn']++), 'nickname');
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'A B', 'Fresh', 'zz99zz', 3, '5.5.5.'.$GLOBALS['ipn']++), 'dupr_id');
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'A B', '', null, 3, '5.5.5.'.$GLOBALS['ipn']++), 'nickname');
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'A B', 'Fresh', null, 7, '5.5.5.'.$GLOBALS['ipn']++), 'stars');
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'A B', 'Fresh', 'bad', 3, '5.5.5.'.$GLOBALS['ipn']++), 'dupr_id');
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, '', 'Fresh', null, 3, '5.5.5.'.$GLOBALS['ipn']++), 'name');
    expect(Player::query()->count())->toBe(1);
});

test('rate limits: searches, submits and registrations per IP', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);

    for ($i = 0; $i < SelfCheckInService::SEARCH_PER_MINUTE; $i++) {
        selfSvc()->search($session, (string) $session->checkin_token, 'ab', '6.6.6.6');
    }
    expectInvalid(fn () => selfSvc()->search($session, (string) $session->checkin_token, 'ab', '6.6.6.6'), 'throttle');
    selfSvc()->search($session, (string) $session->checkin_token, 'ab', '6.6.6.7');

    $player = Player::factory()->for($club)->create();
    for ($i = 0; $i < SelfCheckInService::SUBMITS_PER_MINUTE; $i++) {
        selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, null, '7.7.7.7');
    }
    expectInvalid(fn () => selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, null, '7.7.7.7'), 'throttle');

    for ($i = 0; $i < SelfCheckInService::REGISTRATIONS_PER_HOUR; $i++) {
        selfSvc()->register($session, (string) $session->checkin_token, 'Reg '.$i, 'nick'.$i, null, 2, '8.8.8.8');
        RateLimiter::clear('selfcheckin:submit:8.8.8.8:'.$session->id);
    }
    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'Reg X', 'nickx', null, 2, '8.8.8.8'), 'throttle');
    expect(Player::query()->where('nickname', 'nickx')->exists())->toBeFalse();
});

test('removeCheckIn is staff only and deletes entry and spam player', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = selfLiveSession($club);
    $res = selfSvc()->register($session, (string) $session->checkin_token, 'Spam Bot', 'spam', null, 1, '10.0.0.1');
    $player = Player::query()->where('public_id', $res['player_id'])->firstOrFail();

    expect(fn () => app(CheckInService::class)->removeCheckIn($session, $player, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    Event::fake([PlaySessionChanged::class]);
    app(CheckInService::class)->removeCheckIn($session, $player, $user);

    expect(SessionPlayer::query()->where('player_id', $player->id)->exists())->toBeFalse()
        ->and(Player::query()->whereKey($player->id)->exists())->toBeFalse();
    Event::assertDispatched(PlaySessionChanged::class);
});

test('removeCheckIn keeps a normal roster player and blocks players with matches', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = selfLiveSession($club);
    $svc = app(CheckInService::class);

    $regular = Player::factory()->for($club)->create();
    $svc->checkIn($session, $regular);
    $svc->removeCheckIn($session, $regular, $user);
    expect(Player::query()->whereKey($regular->id)->exists())->toBeTrue()
        ->and(SessionPlayer::query()->where('player_id', $regular->id)->exists())->toBeFalse();

    $players = Player::factory()->count(4)->for($club)->create();
    foreach ($players as $p) {
        $svc->checkIn($session, $p);
    }
    $match = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Staged->value)->firstOrFail();
    $inMatch = Player::query()->findOrFail(MatchPlayer::query()->where('match_id', $match->id)->value('player_id'));

    expectInvalid(fn () => $svc->removeCheckIn($session, $inMatch, $user), 'player');
    expect(SessionPlayer::query()->where('player_id', $inMatch->id)->exists())->toBeTrue();

    expectInvalid(fn () => $svc->removeCheckIn($session, Player::factory()->for($club)->create(), $user), 'player');
});

test('removeCheckIn keeps a self-registered player who has history elsewhere', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $first = PlaySession::factory()->ended()->for($club)->create();
    $second = selfLiveSession($club);
    $player = Player::factory()->for($club)->create(['self_registered_at' => now(), 'self_registered_session_id' => $first->id]);
    SessionPlayer::factory()->create(['play_session_id' => $first->id, 'player_id' => $player->id]);
    app(CheckInService::class)->checkIn($second, $player);

    app(CheckInService::class)->removeCheckIn($second, $player, $user);

    expect(Player::query()->whereKey($player->id)->exists())->toBeTrue();
});

test('public snapshot has only public names and no full names or DUPR data', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    $checkIn = app(CheckInService::class);
    $names = ['Alice Anderson', 'Bob Brown', 'Carol Chen', 'Dave Diaz', 'Eve Evans', 'Finn Fox'];
    foreach ($names as $i => $n) {
        $p = Player::factory()->for($club)->create(['name' => $n, 'dupr_id' => 'ID000'.$i, 'nickname' => $i === 0 ? 'Ally' : null]);
        $checkIn->checkIn($session, $p);
    }
    app(CheckInService::class)->goOnBreak($session, Player::query()->where('name', 'Finn Fox')->firstOrFail());

    $snap = app(PublicSessionView::class)->snapshot($session->fresh());
    $json = json_encode($snap);

    expect($snap['status'])->toBe('live')
        ->and($snap['courts'])->toHaveCount($session->courts)
        ->and($snap['up_next'])->toHaveCount(1)
        ->and($snap['on_break'])->toHaveCount(1)
        ->and($snap['players'])->toHaveCount(6)
        ->and($snap['waiting'][0])->toHaveKeys(['position', 'id', 'name', 'estimate_minutes'])
        ->and($snap['waiting'][0]['position'])->toBe(1)
        ->and($json)->toContain('Ally')->toContain('Bob B.')
        ->and($json)->not->toContain('Anderson')->not->toContain('Brown')->not->toContain('ID000')
        ->not->toContain('stars')->not->toContain('dupr');
});

test('public snapshot is empty for draft and ended sessions', function () {
    $draft = PlaySession::factory()->create();
    $ended = PlaySession::factory()->ended()->create();

    foreach ([$draft, $ended] as $s) {
        $snap = app(PublicSessionView::class)->snapshot($s);
        expect($snap['courts'])->toBe([])->and($snap['waiting'])->toBe([])->and($snap['players'])->toBe([]);
    }
    expect(app(PublicSessionView::class)->snapshot($draft)['status'])->toBe('draft');
});

test('a stale token is rejected after regenerate for search, checkIn and register', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = selfLiveSession($club);
    $player = Player::factory()->for($club)->create();
    $old = (string) $session->checkin_token;

    app(PlaySessionService::class)->regenerateCheckinToken($session, $user);

    expectInvalid(fn () => selfSvc()->search($session, $old, 'ab', '11.0.0.1'), 'session');
    expectInvalid(fn () => selfSvc()->checkIn($session, $old, $player->public_id, null, '11.0.0.1'), 'session');
    expectInvalid(fn () => selfSvc()->register($session, $old, 'A B', 'nn', null, 3, '11.0.0.1'), 'session');
    expect(SessionPlayer::query()->count())->toBe(0);

    selfSvc()->checkIn($session, (string) $session->checkin_token, $player->public_id, null, '11.0.0.1');
    expect(SessionPlayer::query()->count())->toBe(1);
});

test('a failed registration does not use the hourly quota', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    Player::factory()->for($club)->create(['nickname' => 'Taken']);
    $token = (string) $session->checkin_token;

    for ($i = 0; $i < 8; $i++) {
        RateLimiter::clear('selfcheckin:submit:12.0.0.1:'.$session->id);
        expectInvalid(fn () => selfSvc()->register($session, $token, 'A B', 'taken', null, 3, '12.0.0.1'), 'nickname');
    }
    selfSvc()->register($session, $token, 'A B', 'fresh', null, 3, '12.0.0.1');

    expect(Player::query()->where('nickname', 'fresh')->exists())->toBeTrue();
});

test('unknown and other-club player public ids are rejected', function () {
    $session = selfLiveSession();
    $foreign = Player::factory()->create();
    $token = (string) $session->checkin_token;

    expectInvalid(fn () => selfSvc()->checkIn($session, $token, 'nope', null, '13.0.0.1'), 'player');
    expectInvalid(fn () => selfSvc()->checkIn($session, $token, (string) $foreign->public_id, null, '13.0.0.1'), 'player');
    expect($foreign->public_id)->toMatch('/^[a-z0-9]{12}$/');
});

test('checking in a player on break returns them from break explicitly', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    $player = Player::factory()->for($club)->create();
    $token = (string) $session->checkin_token;
    selfSvc()->checkIn($session, $token, $player->public_id, null, '14.0.0.1');
    app(CheckInService::class)->goOnBreak($session, $player);

    $rows = selfSvc()->search($session, $token, $player->name, '14.0.0.1');
    $res = selfSvc()->checkIn($session, $token, $player->public_id, null, '14.0.0.1');

    expect($rows[0]['status'])->toBe('break')
        ->and($res['result'])->toBe('returned_from_break')
        ->and(SessionPlayer::query()->where('player_id', $player->id)->firstOrFail()->status)->toBe(SessionPlayerStatus::Waiting);
});

test('snapshot exposes only public id strings for players', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    foreach (Player::factory()->count(6)->for($club)->create() as $p) {
        app(CheckInService::class)->checkIn($session, $p);
    }

    $snap = app(PublicSessionView::class)->snapshot($session->fresh());
    $ids = collect($snap['players'])->pluck('id')
        ->merge(collect($snap['waiting'])->pluck('id'))
        ->merge(collect($snap['up_next'])->flatMap(fn ($m) => $m['player_ids']));

    expect($ids)->not->toBeEmpty()
        ->and($ids->every(fn ($id) => is_string($id) && preg_match('/^[a-z0-9]{12}$/', $id) === 1))->toBeTrue();
});

test('self-registered marker follows the registration session, not later check-ins', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $a = PlaySession::factory()->for($club)->create();
    $res = selfSvc()->register($a, (string) $a->checkin_token, 'Reg Person', 'regp', null, 3, '15.0.0.1');
    $player = Player::query()->where('public_id', $res['player_id'])->firstOrFail();
    $b = PlaySession::factory()->for($club)->create();
    $svc = app(CheckInService::class);
    $svc->checkIn($b, $player);

    expect(SessionPlayer::query()->selfRegisteredHere()->where('play_session_id', $a->id)->count())->toBe(1)
        ->and(SessionPlayer::query()->selfRegisteredHere()->where('play_session_id', $b->id)->count())->toBe(0);

    // Removing from the registration session keeps the player: they have an entry in B.
    $svc->removeCheckIn($a, $player, $user);
    expect(Player::query()->whereKey($player->id)->exists())->toBeTrue();

    // Removing from B never hard-deletes: B is not the registration session.
    $svc->removeCheckIn($b, $player, $user);
    expect(Player::query()->whereKey($player->id)->exists())->toBeTrue()
        ->and(SessionPlayer::query()->where('player_id', $player->id)->exists())->toBeFalse();
});

test('the deferred broadcast fires at the end of a real request', function () {
    Event::fake([SessionUpdated::class]);
    $session = PlaySession::factory()->create();
    Route::post('/_test/touch', function () use ($session) {
        PlaySessionChanged::dispatch($session->id);
        PlaySessionChanged::dispatch($session->id);

        return response()->noContent();
    });

    $this->post('/_test/touch')->assertNoContent();

    Event::assertDispatchedTimes(SessionUpdated::class, 1);
});

test('the per-session cap error uses the register key', function () {
    $club = Club::factory()->create();
    $session = selfLiveSession($club);
    for ($i = 0; $i < SelfCheckInService::REGISTRATIONS_PER_SESSION; $i++) {
        $p = Player::factory()->for($club)->create(['self_registered_at' => now(), 'self_registered_session_id' => $session->id]);
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $p->id]);
    }

    expectInvalid(fn () => selfSvc()->register($session, (string) $session->checkin_token, 'One More', 'lastone', null, 2, '16.0.0.1'), 'register');
});
