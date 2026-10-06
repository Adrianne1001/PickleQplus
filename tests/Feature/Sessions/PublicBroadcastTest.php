<?php

use App\Enums\MatchStatus;
use App\Events\PlaySessionChanged;
use App\Events\SessionUpdated;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

test('public id is generated, 12 lowercase alphanumerics and unique', function () {
    $club = Club::factory()->create();
    $a = app(PlaySessionService::class)->create($club);
    $b = PlaySession::factory()->for($club)->create();

    expect($a->public_id)->toMatch('/^[a-z0-9]{12}$/')
        ->and($b->public_id)->toMatch('/^[a-z0-9]{12}$/')
        ->and($a->public_id)->not->toBe($b->public_id);
});

test('lookup by public id is scoped to the club', function () {
    $club = Club::factory()->create();
    $other = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create();

    expect(PlaySession::findByPublicId($club, $session->public_id)?->is($session))->toBeTrue()
        ->and(PlaySession::findByPublicId($other, $session->public_id))->toBeNull()
        ->and(PlaySession::findByPublicId($club, 'nope'))->toBeNull();
    PlaySession::findByPublicIdOrFail($other, $session->public_id);
})->throws(ModelNotFoundException::class);

test('broadcast goes on the public channel as session.updated with no player data', function () {
    Event::fake([SessionUpdated::class]);
    $session = PlaySession::factory()->create();

    PlaySessionChanged::dispatch($session->id);
    defer()->invoke();

    Event::assertDispatched(SessionUpdated::class, function (SessionUpdated $e) use ($session) {
        $channels = $e->broadcastOn();

        return $e->broadcastAs() === 'session.updated'
            && $channels[0] instanceof Channel
            && $channels[0]->name === 'play-session.'.$session->public_id
            && array_keys($e->broadcastWith()) === ['public_id', 'at']
            && $e->broadcastWith()['public_id'] === $session->public_id;
    });
});

test('many changes in one action broadcast once per session', function () {
    Event::fake([SessionUpdated::class]);
    $a = PlaySession::factory()->create();
    $b = PlaySession::factory()->create();

    PlaySessionChanged::dispatch($a->id);
    PlaySessionChanged::dispatch($a->id);
    PlaySessionChanged::dispatch($b->id);
    PlaySessionChanged::dispatch($a->id);
    defer()->invoke();

    Event::assertDispatchedTimes(SessionUpdated::class, 2);
});

test('finishing a match that refills and auto-starts broadcasts once', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->live()->for($club)->create(['courts' => 1, 'auto_fill' => true]);
    $checkIn = app(CheckInService::class);
    foreach (Player::factory()->count(8)->for($club)->create() as $player) {
        $checkIn->checkIn($session, $player);
    }
    defer()->invoke();
    $match = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->firstOrFail();

    Event::fake([SessionUpdated::class]);
    app(MatchService::class)->finish($session, $match, 11, 5);
    defer()->invoke();

    Event::assertDispatchedTimes(SessionUpdated::class, 1);
});

test('a broadcasting failure never fails the action', function () {
    $session = PlaySession::factory()->create();
    Event::listen(SessionUpdated::class, fn () => throw new RuntimeException('Reverb down'));

    PlaySessionChanged::dispatch($session->id);
    defer()->invoke();

    expect(true)->toBeTrue();
});

test('tv id is generated, unique, club-scoped and resettable by members only', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $a = PlaySession::factory()->for($club)->create();
    $b = PlaySession::factory()->for($club)->ended()->create();

    expect($a->tv_id)->toHaveLength(32)->not->toBe($b->tv_id)
        ->and(PlaySession::findByTvId($club, $a->tv_id)?->is($a))->toBeTrue()
        ->and(PlaySession::findByTvId(Club::factory()->create(), $a->tv_id))->toBeNull();

    $old = $a->tv_id;
    Event::fake([PlaySessionChanged::class]);
    app(PlaySessionService::class)->resetTvLink($a, $user);
    app(PlaySessionService::class)->resetTvLink($b, $user);

    expect($a->fresh()->tv_id)->toHaveLength(32)->not->toBe($old)
        ->and(PlaySession::findByTvId($club, $old))->toBeNull();
    Event::assertDispatched(PlaySessionChanged::class);

    expect(fn () => app(PlaySessionService::class)->resetTvLink($a, User::factory()->create()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => PlaySession::findByTvIdOrFail(Club::factory()->create(), $a->tv_id))
        ->toThrow(ModelNotFoundException::class);
});
