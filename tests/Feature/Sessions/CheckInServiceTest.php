<?php

use App\Enums\LateArrivalPolicy;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function ciService(): CheckInService
{
    return app(CheckInService::class);
}

/** A live session in a club with the given policy. */
function liveSession(LateArrivalPolicy $policy = LateArrivalPolicy::Minimum): PlaySession
{
    $club = Club::factory()->create(['late_arrival_policy' => $policy]);

    return PlaySession::factory()->for($club)->live()->create();
}

function addPlayer(PlaySession $session, int $played = 0, int $credit = 0, SessionPlayerStatus $status = SessionPlayerStatus::Waiting): SessionPlayer
{
    $player = Player::factory()->for($session->club)->create();

    return SessionPlayer::factory()->create([
        'play_session_id' => $session->id,
        'player_id' => $player->id,
        'games_played' => $played,
        'games_credit' => $credit,
        'status' => $status,
    ]);
}

function newPlayer(PlaySession $session): Player
{
    return Player::factory()->for($session->club)->create();
}

test('check in works in draft and live', function () {
    Event::fake([PlaySessionChanged::class]);
    $draft = PlaySession::factory()->create();
    $live = liveSession();

    $a = ciService()->checkIn($draft, newPlayer($draft));
    $b = ciService()->checkIn($live, newPlayer($live));

    expect($a->status)->toBe(SessionPlayerStatus::Waiting)
        ->and($a->games_credit)->toBe(0)
        ->and($a->queued_at)->not->toBeNull()
        ->and($a->checked_in_at)->not->toBeNull()
        ->and($b->status)->toBe(SessionPlayerStatus::Waiting);
    Event::assertDispatched(PlaySessionChanged::class, 2);
});

test('draft check in never gives credit', function () {
    $draft = PlaySession::factory()->create();
    addPlayer($draft, 5);

    expect(ciService()->checkIn($draft, newPlayer($draft))->games_credit)->toBe(0);
});

test('check in is idempotent for a waiting player', function () {
    $session = liveSession();
    $player = newPlayer($session);

    ciService()->checkIn($session, $player);
    ciService()->checkIn($session, $player);

    expect(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(1);
});

test('inactive, ended and other-club players are rejected', function () {
    $session = liveSession();

    $inactive = Player::factory()->for($session->club)->inactive()->create();
    expect(fn () => ciService()->checkIn($session, $inactive))->toThrow(ValidationException::class);

    $foreign = Player::factory()->create();
    expect(fn () => ciService()->checkIn($session, $foreign))->toThrow(ValidationException::class);

    $ended = PlaySession::factory()->ended()->create();
    expect(fn () => ciService()->checkIn($ended, newPlayer($ended)))->toThrow(ValidationException::class);

    expect(SessionPlayer::query()->count())->toBe(0);
});

test('minimum policy credits up to the lowest effective games', function () {
    $session = liveSession(LateArrivalPolicy::Minimum);
    addPlayer($session, 3);
    addPlayer($session, 1, 2); // effective 3
    addPlayer($session, 5, 0, SessionPlayerStatus::Playing);
    addPlayer($session, 0, 0, SessionPlayerStatus::Break); // ignored
    addPlayer($session, 0, 0, SessionPlayerStatus::Left); // ignored

    $entry = ciService()->checkIn($session, newPlayer($session));

    expect($entry->games_credit)->toBe(3)->and($entry->games_played)->toBe(0);
});

test('back policy credits up to the highest effective games', function () {
    $session = liveSession(LateArrivalPolicy::Back);
    addPlayer($session, 3);
    addPlayer($session, 1, 2);
    addPlayer($session, 5, 1, SessionPlayerStatus::Playing); // effective 6

    expect(ciService()->checkIn($session, newPlayer($session))->games_credit)->toBe(6);
});

test('front policy gives no credit', function () {
    $session = liveSession(LateArrivalPolicy::Front);
    addPlayer($session, 4);

    expect(ciService()->checkIn($session, newPlayer($session))->games_credit)->toBe(0);
});

test('nobody else active means no credit', function () {
    foreach (LateArrivalPolicy::cases() as $policy) {
        $session = liveSession($policy);
        addPlayer($session, 4, 0, SessionPlayerStatus::Break);

        expect(ciService()->checkIn($session, newPlayer($session))->games_credit)->toBe(0);
    }
});

test('returning from break restarts the wait clock and applies credit that only raises', function () {
    $session = liveSession(LateArrivalPolicy::Minimum);
    addPlayer($session, 4);
    addPlayer($session, 6);
    $returning = addPlayer($session, 1, 0, SessionPlayerStatus::Break);
    $returning->update(['queued_at' => now()->subHour()]);

    $entry = ciService()->returnFromBreak($session, $returning->player);

    expect($entry->status)->toBe(SessionPlayerStatus::Waiting)
        ->and($entry->games_credit)->toBe(3) // 4 - 1 played
        ->and($entry->games_played)->toBe(1)
        ->and($entry->queued_at->isAfter(now()->subMinute()))->toBeTrue();

    // Break again and return when the others are lower: credit is never lowered.
    ciService()->goOnBreak($session, $returning->player);
    SessionPlayer::query()->where('play_session_id', $session->id)->where('player_id', '!=', $returning->player_id)->update(['games_played' => 0]);
    $again = ciService()->returnFromBreak($session, $returning->player);

    expect($again->games_credit)->toBe(3);
});

test('return from break needs a player on break', function () {
    $session = liveSession();
    $waiting = addPlayer($session);

    expect(fn () => ciService()->returnFromBreak($session, $waiting->player))->toThrow(ValidationException::class);
    expect(fn () => ciService()->returnFromBreak($session, newPlayer($session)))->toThrow(ValidationException::class);
});

test('checking in a player on break returns them from break', function () {
    $session = liveSession(LateArrivalPolicy::Back);
    addPlayer($session, 5);
    $onBreak = addPlayer($session, 0, 0, SessionPlayerStatus::Break);

    $entry = ciService()->checkIn($session, $onBreak->player);

    expect($entry->status)->toBe(SessionPlayerStatus::Waiting)->and($entry->games_credit)->toBe(5);
    expect(SessionPlayer::query()->where('player_id', $onBreak->player_id)->count())->toBe(1);
});

test('check out and re-check-in of a left player keeps games and applies late policy', function () {
    $session = liveSession(LateArrivalPolicy::Minimum);
    addPlayer($session, 4);
    $mine = addPlayer($session, 2);

    $out = ciService()->checkOut($session, $mine->player);
    expect($out->status)->toBe(SessionPlayerStatus::Left);

    $back = ciService()->checkIn($session, $mine->player);

    expect($back->status)->toBe(SessionPlayerStatus::Waiting)
        ->and($back->games_played)->toBe(2)
        ->and($back->games_credit)->toBe(2) // lowest other effective is 4, minus 2 played
        ->and(SessionPlayer::query()->where('player_id', $mine->player_id)->count())->toBe(1);
});

test('break from a draft session works without credit', function () {
    $draft = PlaySession::factory()->create();
    $entry = addPlayer($draft, 0);

    ciService()->goOnBreak($draft, $entry->player);
    $back = ciService()->returnFromBreak($draft, $entry->player);

    expect($back->status)->toBe(SessionPlayerStatus::Waiting)->and($back->games_credit)->toBe(0);
});

test('a left player cannot go on break and an unknown player cannot check out', function () {
    $session = liveSession();
    $left = addPlayer($session, 0, 0, SessionPlayerStatus::Left);

    expect(fn () => ciService()->goOnBreak($session, $left->player))->toThrow(ValidationException::class);
    expect(fn () => ciService()->checkOut($session, newPlayer($session)))->toThrow(ValidationException::class);
});

test('a playing player cannot check out or go on break', function () {
    $session = liveSession();
    $playing = addPlayer($session, 0, 0, SessionPlayerStatus::Playing);

    expect(fn () => ciService()->checkOut($session, $playing->player))->toThrow(ValidationException::class);
    expect(fn () => ciService()->goOnBreak($session, $playing->player))->toThrow(ValidationException::class);
    expect($playing->fresh()->status)->toBe(SessionPlayerStatus::Playing);
});

test('leaving a staged match voids it and frees the other players', function (string $action) {
    $session = liveSession();
    $leaving = addPlayer($session);
    $others = [addPlayer($session), addPlayer($session), addPlayer($session)];
    $staged = GameMatch::factory()->for($session)->create();
    $unrelated = GameMatch::factory()->for($session)->create();
    foreach ([$leaving, ...$others] as $i => $sp) {
        MatchPlayer::factory()->create(['match_id' => $staged->id, 'player_id' => $sp->player_id, 'team' => $i < 2 ? 'A' : 'B', 'slot' => $i % 2 + 1]);
    }
    MatchPlayer::factory()->create(['match_id' => $unrelated->id, 'player_id' => newPlayer($session)->id]);

    $action === 'out'
        ? ciService()->checkOut($session, $leaving->player)
        : ciService()->goOnBreak($session, $leaving->player);

    expect($staged->fresh()->status)->toBe(MatchStatus::Void)
        ->and($unrelated->fresh()->status)->toBe(MatchStatus::Staged)
        ->and($leaving->fresh()->status)->toBe($action === 'out' ? SessionPlayerStatus::Left : SessionPlayerStatus::Break)
        ->and($others[0]->fresh()->status)->toBe(SessionPlayerStatus::Waiting);
})->with(['out', 'break']);

test('a player can be live in only one session at a time', function () {
    $club = Club::factory()->create(['allow_concurrent_sessions' => true]);
    $one = PlaySession::factory()->for($club)->live()->create();
    $two = PlaySession::factory()->for($club)->live()->create();
    $player = Player::factory()->for($club)->create();

    ciService()->checkIn($one, $player);

    expect(fn () => ciService()->checkIn($two, $player))->toThrow(ValidationException::class);

    ciService()->checkOut($one, $player);
    ciService()->checkIn($two, $player);

    expect(SessionPlayer::query()->where('play_session_id', $two->id)->count())->toBe(1);
});

test('the same player may be in a draft and a live session', function () {
    $club = Club::factory()->create();
    $live = PlaySession::factory()->for($club)->live()->create();
    $draft = PlaySession::factory()->for($club)->create();
    $player = Player::factory()->for($club)->create();

    ciService()->checkIn($live, $player);
    ciService()->checkIn($draft, $player);

    expect(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(2);
});
