<?php

use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\ClubService;
use App\Services\PlaySessionService;
use App\Services\SelfCheckInService;
use App\Services\SessionListService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** @return array<string, list<string>> */
function pubErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function pubSession(?string $password = null, bool $live = true): PlaySession
{
    $factory = PlaySession::factory()->for(Club::factory()->create());
    $session = ($live ? $factory->live() : $factory)->create();
    if ($password !== null) {
        app(SessionListService::class)->setPassword($session, $password);
    }

    return $session->fresh();
}

function pubCheckIn(PlaySession $session, Player $player, ?string $password = null, string $ip = '20.0.0.1', ?string $token = null): array
{
    return app(SelfCheckInService::class)->checkIn($session, $token ?? (string) $session->checkin_token, (string) $player->public_id, $ip, null, $password);
}

// --- the list is the roster for the QR ---

test('a club player who is not on the list is refused by public id, even with the right password', function () {
    $session = pubSession('Court7');
    $unlisted = Player::factory()->for($session->club)->create(['name' => 'Una Listed']);

    $errors = pubErrors(fn () => pubCheckIn($session, $unlisted, 'Court7'));

    expect($errors)->toHaveKey('player')
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('an unlisted player is refused on a session without a password too, and a listed one is accepted', function () {
    $session = pubSession();
    $unlisted = Player::factory()->for($session->club)->create();
    $listed = Player::factory()->for($session->club)->create();
    listPlayers($session, $listed);

    expect(pubErrors(fn () => pubCheckIn($session, $unlisted)))->toHaveKey('player')
        ->and(pubCheckIn($session, $listed)['result'])->toBe('checked_in')
        ->and(SessionPlayer::query()->pluck('player_id')->all())->toBe([$listed->id]);
});

test('listing a player for another session does not open this one', function () {
    $club = Club::factory()->create();
    $a = PlaySession::factory()->for($club)->live()->create();
    $b = PlaySession::factory()->for($club)->live()->create();
    $p = Player::factory()->for($club)->create();
    listPlayers($a, $p);

    expect(pubErrors(fn () => pubCheckIn($b, $p)))->toHaveKey('player')
        ->and(app(SelfCheckInService::class)->search($b, (string) $b->checkin_token, $p->name, '20.0.0.2'))->toBe([])
        ->and(count(app(SelfCheckInService::class)->search($a, (string) $a->checkin_token, $p->name, '20.0.0.2')))->toBe(1);
});

test('a player from another club is refused by public id on a listed session', function () {
    $session = pubSession();
    $foreign = Player::factory()->create();
    listPlayers($session, Player::factory()->for($session->club)->create());

    expect(pubErrors(fn () => pubCheckIn($session, $foreign)))->toHaveKey('player');
});

test('hasList and requiresPassword tell the page what to show', function () {
    $svc = app(SelfCheckInService::class);
    $session = pubSession();

    expect($svc->hasList($session))->toBeFalse()->and($svc->requiresPassword($session))->toBeFalse();

    listPlayers($session, Player::factory()->for($session->club)->create());
    app(SessionListService::class)->setPassword($session, 'Court7');

    expect($svc->hasList($session))->toBeTrue()->and($svc->requiresPassword($session->fresh()))->toBeTrue();
});

// --- the password ---

test('without a password set the check-in ignores whatever password is sent', function () {
    $session = pubSession();
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);

    expect(pubCheckIn($session, $p, 'whatever')['result'])->toBe('checked_in');
});

test('a missing or wrong password is refused and nobody is checked in', function () {
    $session = pubSession('Court7');
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);

    $missing = pubErrors(fn () => pubCheckIn($session, $p, null, '21.0.0.1'));
    $blank = pubErrors(fn () => pubCheckIn($session, $p, '   ', '21.0.0.1'));
    $wrong = pubErrors(fn () => pubCheckIn($session, $p, 'Court8', '21.0.0.1'));

    expect($missing)->toHaveKey('password')
        ->and($blank)->toHaveKey('password')
        ->and($wrong['password'][0])->toBe("That password isn't right.")
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('the right password checks the player in, trimmed and in any case', function (string $typed) {
    $session = pubSession('Court7');
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);

    expect(pubCheckIn($session, $p, $typed, '21.0.0.2')['result'])->toBe('checked_in')
        ->and(SessionPlayer::query()->where('player_id', $p->id)->firstOrFail()->status)->toBe(SessionPlayerStatus::Waiting);
})->with(['Court7', 'court7', 'COURT7', '  Court7  ', "\tcOURT7\n"]);

test('a wrong password counts toward the submit limit', function () {
    $session = pubSession('Court7');
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);
    RateLimiter::clear("selfcheckin:submit:22.0.0.1:{$session->id}");

    for ($i = 0; $i < SelfCheckInService::SUBMITS_PER_MINUTE; $i++) {
        expect(pubErrors(fn () => pubCheckIn($session, $p, 'guess'.$i, '22.0.0.1')))->toHaveKey('password');
    }

    // The 11th attempt is throttled, even with the right password.
    expect(pubErrors(fn () => pubCheckIn($session, $p, 'Court7', '22.0.0.1')))->toHaveKey('throttle')
        ->and(SessionPlayer::query()->count())->toBe(0);

    // Another device is not affected.
    expect(pubCheckIn($session, $p, 'Court7', '22.0.0.9')['result'])->toBe('checked_in');
});

test('the password is checked before the player lookup, so the list cannot be probed without it', function () {
    $session = pubSession('Court7');
    $unlisted = Player::factory()->for($session->club)->create();
    $foreign = Player::factory()->create();

    expect(pubErrors(fn () => pubCheckIn($session, $unlisted, 'nope', '23.0.0.1')))->toHaveKey('password')
        ->and(pubErrors(fn () => pubCheckIn($session, $foreign, 'nope', '23.0.0.1')))->toHaveKey('password');
});

test('a password changed after the page was opened is re-checked inside the lock', function () {
    $session = pubSession('Court7');
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);
    $stale = PlaySession::findOrFail($session->id); // the instance a page would hold

    app(SessionListService::class)->setPassword(PlaySession::findOrFail($session->id), 'Newpass8');

    expect(pubErrors(fn () => pubCheckIn($stale, $p, 'Court7', '24.0.0.1')))->toHaveKey('password')
        ->and(pubCheckIn($stale, $p, 'newpass8', '24.0.0.1')['result'])->toBe('checked_in');
});

test('a password added or cleared after the page was opened takes effect at once', function () {
    $session = pubSession();
    $p = Player::factory()->for($session->club)->create();
    $q = Player::factory()->for($session->club)->create();
    listPlayers($session, $p, $q);
    $stale = PlaySession::findOrFail($session->id);

    app(SessionListService::class)->setPassword(PlaySession::findOrFail($session->id), 'Court7');
    expect(pubErrors(fn () => pubCheckIn($stale, $p, null, '24.0.0.2')))->toHaveKey('password');

    app(SessionListService::class)->clearPassword(PlaySession::findOrFail($session->id));
    expect(pubCheckIn($stale, $q, null, '24.0.0.2')['result'])->toBe('checked_in');
});

// --- the token ---

test('a regenerated token stops a check-in even with the right password', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = PlaySession::factory()->for($club)->live()->create();
    app(SessionListService::class)->setPassword($session, 'Court7');
    $p = Player::factory()->for($club)->create();
    listPlayers($session, $p);
    $old = (string) $session->fresh()->checkin_token;

    app(PlaySessionService::class)->regenerateCheckinToken($session, $user);

    expect(pubErrors(fn () => pubCheckIn($session, $p, 'Court7', '25.0.0.1', $old)))->toHaveKey('session')
        ->and(SessionPlayer::query()->count())->toBe(0);

    // The password survived the new QR.
    expect(pubCheckIn($session->fresh(), $p, 'Court7', '25.0.0.1')['result'])->toBe('checked_in');
});

test('a closed session refuses a check-in', function () {
    $session = pubSession('Court7');
    $p = Player::factory()->for($session->club)->create();
    listPlayers($session, $p);
    $token = (string) $session->checkin_token;
    app(PlaySessionService::class)->end($session);

    expect(pubErrors(fn () => pubCheckIn($session->fresh(), $p, 'Court7', '26.0.0.1', $token)))->toHaveKey('session');
});

// --- search ---

test('search covers only the list and does not ask for the password', function () {
    $session = pubSession('Court7');
    $in = Player::factory()->for($session->club)->create(['name' => 'Lia Listed']);
    Player::factory()->for($session->club)->create(['name' => 'Lia Hidden']);
    listPlayers($session, $in);

    $rows = app(SelfCheckInService::class)->search($session, (string) $session->checkin_token, 'Lia', '27.0.0.1');

    expect($rows)->toHaveCount(1)->and($rows[0]['name'])->toBe('Lia Listed')->and($rows[0]['id'])->toBe($in->public_id);
});

test('self register is gone from the service', function () {
    expect(method_exists(SelfCheckInService::class, 'register'))->toBeFalse()
        ->and(defined(SelfCheckInService::class.'::REGISTRATIONS_PER_HOUR'))->toBeFalse()
        ->and(defined(SelfCheckInService::class.'::REGISTRATIONS_PER_SESSION'))->toBeFalse();
});

// --- optional stars at check-in (P16.10) ---

function pendingPlayer(PlaySession $session, string $name = 'Pen Ding'): Player
{
    $p = Player::factory()->for($session->club)->manual(3)->create(['name' => $name]);
    $p->forceFill(['stars_pending' => true])->save();
    listPlayers($session, $p);

    return $p;
}

function pubCheckInStars(PlaySession $session, Player $player, ?int $stars, ?string $password = null): array
{
    return app(SelfCheckInService::class)->checkIn($session, (string) $session->checkin_token, (string) $player->public_id, '20.0.0.9', null, $password, $stars);
}

test('stars given for a pending manual player are stored and clear the pending flag', function () {
    $session = pubSession();
    $p = pendingPlayer($session);

    $result = pubCheckInStars($session, $p, 5);

    expect($result['result'])->toBe('checked_in')
        ->and($p->fresh()->stars)->toBe(5)
        ->and($p->fresh()->stars_pending)->toBeFalse()
        ->and($p->fresh()->rating_source)->toBe(RatingSource::Manual);
});

test('stars for a pending player who is already checked in are stored too', function () {
    $session = pubSession();
    $p = pendingPlayer($session);
    pubCheckInStars($session, $p, null);
    expect($p->fresh()->stars_pending)->toBeTrue();

    $result = pubCheckInStars($session, $p, 2);

    expect($result['result'])->toBe('already_checked_in')
        ->and($p->fresh()->stars)->toBe(2)->and($p->fresh()->stars_pending)->toBeFalse();
});

test('stars are ignored silently for a player whose skill is already set', function () {
    $session = pubSession();
    $p = Player::factory()->for($session->club)->manual(2)->create();
    listPlayers($session, $p);

    $result = pubCheckInStars($session, $p, 6);

    expect($result['result'])->toBe('checked_in')
        ->and($p->fresh()->stars)->toBe(2)->and($p->fresh()->stars_pending)->toBeFalse();
});

test('stars are ignored silently for a DUPR-rated player', function () {
    $session = pubSession();
    $p = Player::factory()->for($session->club)->rated(4.2)->create();
    listPlayers($session, $p);
    $before = $p->fresh()->stars;

    pubCheckInStars($session, $p, 1);

    expect($p->fresh()->stars)->toBe($before)->and($p->fresh()->rating_source)->toBe(RatingSource::Dupr);
});

test('no stars (null) keeps the pending flag', function () {
    $session = pubSession();
    $p = pendingPlayer($session);

    pubCheckInStars($session, $p, null);

    expect($p->fresh()->stars)->toBe(3)->and($p->fresh()->stars_pending)->toBeTrue()
        ->and(SessionPlayer::query()->where('player_id', $p->id)->count())->toBe(1);
});

test('out-of-range stars are a stars error and nothing is checked in', function (int $stars) {
    $session = pubSession();
    $p = pendingPlayer($session);

    expect(pubErrors(fn () => pubCheckInStars($session, $p, $stars)))->toHaveKey('stars')
        ->and($p->fresh()->stars_pending)->toBeTrue()
        ->and(SessionPlayer::query()->count())->toBe(0);
})->with([0, 7, -1]);

test('a wrong password does not set the stars', function () {
    $session = pubSession('Court7');
    $p = pendingPlayer($session);

    expect(pubErrors(fn () => pubCheckInStars($session, $p, 5, 'nope')))->toHaveKey('password')
        ->and($p->fresh()->stars)->toBe(3)->and($p->fresh()->stars_pending)->toBeTrue()
        ->and(SessionPlayer::query()->count())->toBe(0);

    pubCheckInStars($session, $p, 5, 'court7');
    expect($p->fresh()->stars)->toBe(5)->and($p->fresh()->stars_pending)->toBeFalse();
});

test('a stale token does not set the stars', function () {
    $session = pubSession();
    $p = pendingPlayer($session);

    $errors = pubErrors(fn () => app(SelfCheckInService::class)->checkIn($session, 'wrong-token', (string) $p->public_id, '20.0.0.9', null, null, 5));

    expect($errors)->toHaveKey('session')->and($p->fresh()->stars_pending)->toBeTrue();
});

test('search rows say whether the skill is pending but never expose stars', function () {
    $session = pubSession();
    pendingPlayer($session, 'Pen Ding');
    $set = Player::factory()->for($session->club)->manual(4)->create(['name' => 'Pen Set']);
    $rated = Player::factory()->for($session->club)->rated(4.8)->create(['name' => 'Pen Rated']);
    listPlayers($session, $set, $rated);

    $rows = collect(app(SelfCheckInService::class)->search($session, (string) $session->checkin_token, 'Pen', '20.0.0.9'))->keyBy('name');

    expect($rows['Pen Ding']['stars_pending'])->toBeTrue()
        ->and($rows['Pen Set']['stars_pending'])->toBeFalse()
        ->and($rows['Pen Rated']['stars_pending'])->toBeFalse()
        ->and($rows->flatMap(fn ($r) => array_keys($r))->unique()->all())->not->toContain('stars');
});
