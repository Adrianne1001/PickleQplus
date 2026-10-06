<?php

use App\Enums\LateArrivalPolicy;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\ClubService;
use App\Services\PlaySessionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

function psService(): PlaySessionService
{
    return app(PlaySessionService::class);
}

test('create applies club defaults', function () {
    $club = Club::factory()->create(['default_courts' => 6]);
    Event::fake([PlaySessionChanged::class]);

    $session = psService()->create($club, ['name' => ' Friday ']);

    expect($session->club_id)->toBe($club->id)
        ->and($session->name)->toBe('Friday')
        ->and($session->courts)->toBe(6)
        ->and($session->up_next_count)->toBe(1)
        ->and($session->auto_fill)->toBeFalse()
        ->and($session->scoring)->toBe(['type' => 'side_out', 'games' => 1, 'to' => 11, 'win_by' => 2])
        ->and($session->status)->toBe(SessionStatus::Draft);
    Event::assertDispatched(PlaySessionChanged::class, fn ($e) => $e->playSessionId === $session->id);
});

test('settings are validated', function (array $bad) {
    $club = Club::factory()->create();

    expect(fn () => psService()->create($club, $bad))->toThrow(ValidationException::class);
})->with([
    'courts 0' => [['courts' => 0]],
    'courts 51' => [['courts' => 51]],
    'up next 0' => [['up_next_count' => 0]],
    'up next 4' => [['up_next_count' => 4]],
    'to 13' => [['scoring' => ['to' => 13]]],
    'win by 3' => [['scoring' => ['to' => 11, 'win_by' => 3]]],
]);

test('valid boundary settings are accepted', function () {
    $club = Club::factory()->create();

    $session = psService()->create($club, ['courts' => 50, 'up_next_count' => 3, 'auto_fill' => true, 'scoring' => ['type' => 'side_out', 'games' => 1, 'to' => 21, 'win_by' => 1]]);

    expect($session->courts)->toBe(50)->and($session->scoring['to'])->toBe(21)->and($session->auto_fill)->toBeTrue();
});

test('update changes settings and merges scoring', function () {
    $session = PlaySession::factory()->live()->create(['courts' => 4]);

    psService()->update($session, ['courts' => 6, 'scoring' => ['to' => 15]]);

    $fresh = $session->fresh();
    expect($fresh->courts)->toBe(6)
        ->and($fresh->scoring)->toBe(['type' => 'side_out', 'games' => 1, 'to' => 15, 'win_by' => 2]);
});

test('a court with a playing match cannot be removed', function () {
    $session = PlaySession::factory()->live()->create(['courts' => 4]);
    GameMatch::factory()->for($session)->playing(4)->create();
    GameMatch::factory()->for($session)->playing(1)->create();

    expect(fn () => psService()->update($session, ['courts' => 3]))->toThrow(ValidationException::class);
    expect($session->fresh()->courts)->toBe(4);

    psService()->update($session, ['courts' => 4]);
});

test('removing a free court is allowed', function () {
    $session = PlaySession::factory()->live()->create(['courts' => 4]);
    GameMatch::factory()->for($session)->playing(2)->create();

    psService()->update($session, ['courts' => 3]);

    expect($session->fresh()->courts)->toBe(3);
});

test('an ended session cannot be updated', function () {
    $session = PlaySession::factory()->ended()->create();

    expect(fn () => psService()->update($session, ['courts' => 2]))->toThrow(ValidationException::class);
});

test('lifecycle draft to live to ended', function () {
    $session = PlaySession::factory()->create();

    expect(fn () => psService()->end($session))->toThrow(ValidationException::class);

    psService()->start($session);
    expect($session->fresh()->status)->toBe(SessionStatus::Live)->and($session->fresh()->started_at)->not->toBeNull();
    expect(fn () => psService()->start($session))->toThrow(ValidationException::class);

    psService()->end($session);
    expect($session->fresh()->status)->toBe(SessionStatus::Ended)->and($session->fresh()->ended_at)->not->toBeNull();
    expect(fn () => psService()->start($session))->toThrow(ValidationException::class);
});

test('ending is blocked while a match is playing', function () {
    $session = PlaySession::factory()->live()->create();
    $match = GameMatch::factory()->for($session)->playing()->create();

    expect(fn () => psService()->end($session))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe(SessionStatus::Live);

    $match->update(['status' => MatchStatus::Done]);
    psService()->end($session);
    expect($session->fresh()->status)->toBe(SessionStatus::Ended);
});

test('ending voids staged matches', function () {
    $session = PlaySession::factory()->live()->create();
    $staged = GameMatch::factory()->for($session)->create();
    $done = GameMatch::factory()->for($session)->create(['status' => MatchStatus::Done]);

    psService()->end($session);

    expect($staged->fresh()->status)->toBe(MatchStatus::Void)->and($done->fresh()->status)->toBe(MatchStatus::Done);
});

test('only drafts can be deleted', function () {
    $draft = PlaySession::factory()->create();
    $live = PlaySession::factory()->live()->create();
    $ended = PlaySession::factory()->ended()->create();

    expect(fn () => psService()->delete($live))->toThrow(ValidationException::class);
    expect(fn () => psService()->delete($ended))->toThrow(ValidationException::class);

    $player = Player::factory()->for($draft->club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $draft->id, 'player_id' => $player->id]);
    psService()->delete($draft);

    expect(PlaySession::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(SessionPlayer::query()->count())->toBe(0)
        ->and(PlaySession::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('only one live session per club unless concurrent sessions are allowed', function () {
    $club = Club::factory()->create();
    $first = PlaySession::factory()->for($club)->create();
    $second = PlaySession::factory()->for($club)->create();
    $otherClub = PlaySession::factory()->create();

    psService()->start($first);
    expect(fn () => psService()->start($second))->toThrow(ValidationException::class);
    psService()->start($otherClub);

    $club->update(['allow_concurrent_sessions' => true]);
    psService()->start($second->fresh());
    expect($second->fresh()->status)->toBe(SessionStatus::Live);
});

test('ending a session frees the club for the next one', function () {
    $club = Club::factory()->create();
    $first = PlaySession::factory()->for($club)->live()->create();
    $second = PlaySession::factory()->for($club)->create();

    psService()->end($first);
    psService()->start($second);

    expect($second->fresh()->status)->toBe(SessionStatus::Live);
});

test('starting is blocked when a checked-in player is live in another session', function () {
    $club = Club::factory()->create(['allow_concurrent_sessions' => true]);
    $player = Player::factory()->for($club)->create();
    $live = PlaySession::factory()->for($club)->live()->create();
    $draft = PlaySession::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $live->id, 'player_id' => $player->id]);
    SessionPlayer::factory()->create(['play_session_id' => $draft->id, 'player_id' => $player->id]);

    expect(fn () => psService()->start($draft))->toThrow(ValidationException::class);

    SessionPlayer::query()->where('play_session_id', $draft->id)->update(['status' => SessionPlayerStatus::Left->value]);
    psService()->start($draft);
    expect($draft->fresh()->status)->toBe(SessionStatus::Live);
});

test('club session settings are validated and saved', function () {
    $club = Club::factory()->create();
    $service = app(ClubService::class);

    expect($club->fresh()->late_arrival_policy)->toBe(LateArrivalPolicy::Minimum)
        ->and($club->fresh()->allow_concurrent_sessions)->toBeFalse();

    $service->updateSessionSettings($club, ['late_arrival_policy' => 'back', 'allow_concurrent_sessions' => true]);
    expect($club->fresh()->late_arrival_policy)->toBe(LateArrivalPolicy::Back)
        ->and($club->fresh()->allow_concurrent_sessions)->toBeTrue();

    $service->updateSessionSettings($club, ['late_arrival_policy' => LateArrivalPolicy::Front]);
    expect($club->fresh()->late_arrival_policy)->toBe(LateArrivalPolicy::Front)
        ->and($club->fresh()->allow_concurrent_sessions)->toBeTrue();

    expect(fn () => $service->updateSessionSettings($club, ['late_arrival_policy' => 'sideways']))
        ->toThrow(ValidationException::class);
    expect($club->fresh()->late_arrival_policy)->toBe(LateArrivalPolicy::Front);
});

test('only owners may change club session settings', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    expect(Gate::forUser($owner)->allows('manageSettings', $club))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('manageSettings', $club))->toBeFalse();
});

test('play session policy: owners and staff manage, others get 404', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $outsider = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();
    $session = PlaySession::factory()->for($club)->create();

    foreach (['view', 'update', 'delete', 'manage'] as $ability) {
        expect(Gate::forUser($owner)->allows($ability, $session))->toBeTrue()
            ->and(Gate::forUser($staff)->allows($ability, $session))->toBeTrue();
        expect(Gate::forUser($outsider)->inspect($ability, $session)->status())->toBe(404);
    }

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($staff)->allows($ability, [PlaySession::class, $club]))->toBeTrue();
        expect(Gate::forUser($outsider)->inspect($ability, [PlaySession::class, $club])->status())->toBe(404);
    }
});

test('a session from another club is a 404 through scoped route binding', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    $otherClub = Club::factory()->create();
    $mine = PlaySession::factory()->for($club)->create();
    $theirs = PlaySession::factory()->for($otherClub)->create();

    Route::middleware(['web', 'auth', 'club.member'])
        ->prefix('clubs/{club:slug}')
        ->scopeBindings()
        ->get('sessions/{session}', fn (Club $club, PlaySession $session) => response()->json(['id' => $session->id]))
        ->name('test.sessions.show');

    $this->actingAs($user)->get("/clubs/{$club->slug}/sessions/{$mine->id}")
        ->assertOk()->assertJson(['id' => $mine->id]);
    $this->actingAs($user)->get("/clubs/{$club->slug}/sessions/{$theirs->id}")->assertNotFound();
});

test('match players are linked to matches and sessions', function () {
    $session = PlaySession::factory()->live()->create();
    $match = GameMatch::factory()->for($session)->create();
    $player = Player::factory()->for($session->club)->create();
    MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $player->id]);

    expect($session->matches)->toHaveCount(1)
        ->and($match->matchPlayers)->toHaveCount(1)
        ->and($session->club->playSessions)->toHaveCount(1);
});
