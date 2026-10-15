<?php

use App\Enums\MatchStatus;
use App\Events\SessionExpired;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\GuestPurgeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * An expired guest club with a session, players, a check-in, a match, files and caches.
 *
 * @return array{0: Club, 1: PlaySession}
 */
function expiredGuestWithData(): array
{
    [$club, $session] = guestBoard([], now()->subMinute());
    $players = Player::factory()->for($club)->manual(3)->count(4)->create();
    foreach ($players as $p) {
        SessionPlayer::query()->create(['play_session_id' => $session->id, 'player_id' => $p->id, 'status' => 'waiting', 'games_played' => 0, 'games_credit' => 0, 'checked_in_at' => now()]);
    }
    $match = GameMatch::factory()->create(['play_session_id' => $session->id, 'status' => MatchStatus::Done, 'team_a_score' => 11, 'team_b_score' => 3, 'court_no' => 1, 'started_at' => now()->subMinutes(10), 'finished_at' => now()]);
    foreach ([[0, 'A', 1], [1, 'A', 2], [2, 'B', 1], [3, 'B', 2]] as [$i, $team, $slot]) {
        MatchPlayer::create(['match_id' => $match->id, 'player_id' => $players[$i]->id, 'team' => $team, 'slot' => $slot]);
    }

    Storage::fake('local');
    Storage::disk('local')->put("podium/{$session->id}/abc-v1.png", 'png');
    Storage::disk('local')->put("dupr-exports/{$club->id}/export.csv", 'csv');
    Cache::put("public-session-results:{$session->id}", ['x'], 600);
    Cache::put("public-sessions:{$club->id}:1", ['x'], 600);
    Cache::put("public-sessions-total:{$club->id}", 1, 600);
    Cache::put("public-stats:{$club->id}:all_time", ['x'], 600);

    return [$club, $session];
}

test('the purge command deletes an expired guest club and everything under it', function () {
    Event::fake([SessionExpired::class]);
    [$club, $session] = expiredGuestWithData();
    $other = Club::factory()->withOwner()->create();
    PlaySession::factory()->for($other)->create();

    $this->artisan('pickleq:purge-guest-clubs')->expectsOutputToContain('Purged 1')->assertSuccessful();

    expect(Club::query()->whereKey($club->id)->exists())->toBeFalse()
        ->and(PlaySession::query()->where('club_id', $club->id)->count())->toBe(0)
        ->and(Player::query()->where('club_id', $club->id)->count())->toBe(0)
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(0)
        ->and(GameMatch::query()->where('play_session_id', $session->id)->count())->toBe(0)
        ->and(MatchPlayer::query()->count())->toBe(0)
        ->and(Club::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(PlaySession::query()->where('club_id', $other->id)->count())->toBe(1);

    expect(Storage::disk('local')->exists("podium/{$session->id}/abc-v1.png"))->toBeFalse()
        ->and(Storage::disk('local')->exists("dupr-exports/{$club->id}/export.csv"))->toBeFalse()
        ->and(Cache::has("public-session-results:{$session->id}"))->toBeFalse()
        ->and(Cache::has("public-sessions:{$club->id}:1"))->toBeFalse()
        ->and(Cache::has("public-sessions-total:{$club->id}"))->toBeFalse()
        ->and(Cache::has("public-stats:{$club->id}:all_time"))->toBeFalse()
        ->and(Cache::has('guest-gone:'.$club->slug))->toBeTrue();

    Event::assertDispatched(SessionExpired::class, fn (SessionExpired $e) => $e->publicId === $session->public_id);
});

test('the session.expired event broadcasts on the public session channel', function () {
    $event = new SessionExpired('abc123def456');

    expect($event->broadcastAs())->toBe('session.expired')
        ->and($event->broadcastOn()[0]->name)->toBe('play-session.abc123def456')
        ->and($event->broadcastWith())->toBe(['public_id' => 'abc123def456']);
});

test('an unexpired guest club, a claimed club and a normal club are never purged', function () {
    $active = Club::factory()->guest()->create();
    $claimed = Club::factory()->withOwner()->create(['guest_claimed_at' => now()->subDay(), 'guest_expires_at' => now()->subDay()]);
    $normal = Club::factory()->create();

    expect(app(GuestPurgeService::class)->purgeExpired())->toBe(0)
        ->and(app(GuestPurgeService::class)->purge($active->id))->toBeFalse()
        ->and(app(GuestPurgeService::class)->purge($claimed->id))->toBeFalse()
        ->and(app(GuestPurgeService::class)->purge($normal->id))->toBeFalse()
        ->and(Club::query()->count())->toBe(3);
});

test('purging re-checks inside the lock: a club that was revived is skipped', function () {
    [$club] = guestBoard([], now()->subMinute());
    $stale = Club::query()->expiredGuests()->first();
    Club::query()->whereKey($club->id)->update(['guest_expires_at' => now()->addHour()]);

    expect(app(GuestPurgeService::class)->purge((int) $stale->id))->toBeFalse()
        ->and(Club::query()->whereKey($club->id)->exists())->toBeTrue();
});

test('purging many clubs works in chunks', function () {
    Club::factory()->guest(null, now()->subMinute())->count(5)->create();

    expect(app(GuestPurgeService::class)->purgeExpired(2))->toBe(5)
        ->and(Club::query()->count())->toBe(0);
});

test('a failing broadcast never fails the purge', function () {
    Event::listen(SessionExpired::class, fn () => throw new RuntimeException('Reverb is down'));
    [$club, $session] = expiredGuestWithData();

    expect(app(GuestPurgeService::class)->purgeExpired())->toBe(1)
        ->and(Club::query()->whereKey($club->id)->exists())->toBeFalse()
        ->and(Cache::has('guest-gone:'.$club->slug))->toBeTrue()
        ->and(Storage::disk('local')->exists("podium/{$session->id}/abc-v1.png"))->toBeFalse();
});

test('the old guest links show the expired page after a purge', function () {
    [$club, $session] = expiredGuestWithData();
    $slug = $club->slug;
    app(GuestPurgeService::class)->purgeExpired();

    $this->get("/clubs/{$slug}/sessions/{$session->id}")->assertRedirect(route('guest.expired'));
    $this->get(route('guest.expired'))->assertStatus(410);
});

test('the purge is scheduled every 5 minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'pickleq:purge-guest-clubs'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('deleting a normal club also removes its podium images and caches', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->ended()->create();
    Storage::disk('local')->put("podium/{$session->id}/x.gif", 'gif');
    Storage::disk('local')->put("dupr-exports/{$club->id}/e.csv", 'csv');
    Cache::put("public-session-results:{$session->id}", ['x'], 600);

    app(ClubService::class)->delete($club);

    expect(Storage::disk('local')->exists("podium/{$session->id}/x.gif"))->toBeFalse()
        ->and(Storage::disk('local')->exists("dupr-exports/{$club->id}/e.csv"))->toBeFalse()
        ->and(Cache::has("public-session-results:{$session->id}"))->toBeFalse();
});

// --- expired but not purged yet ---

test('public pages of an expired, not yet purged guest club are 404', function () {
    [$club, $session] = guestBoard(['status' => 'live', 'started_at' => now()], now()->subMinute());
    $session->forceFill(['checkin_token' => 'tok'.str_repeat('a', 29)])->save();

    $this->get(route('public.queue', [$club, $session->public_id]))->assertNotFound();
    $this->get(route('public.tv', [$club, $session->tv_id]))->assertNotFound();
    $this->get(route('public.checkin', $session->checkin_token))->assertNotFound();
    $this->get(route('public.session.podium-png', [$club, $session->public_id]))->assertNotFound();
    $this->get(route('public.session.podium-gif', [$club, $session->public_id]))->assertNotFound();
});

test('the same public pages work for an unexpired guest club', function () {
    [$club, $session] = guestBoard(['status' => 'live', 'started_at' => now()]);

    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk();
    $this->get(route('public.tv', [$club, $session->tv_id]))->assertOk();
    $this->get(route('public.checkin', $session->checkin_token))->assertOk();
});

test('an event after expiry does not bring the club back for the public pages', function () {
    [$club, $session] = guestBoard(['status' => 'live', 'started_at' => now()], now()->subMinute());
    $player = Player::factory()->for($club)->manual(3)->create();
    expect(fn () => app(CheckInService::class)->checkIn($session, $player))
        ->toThrow(ValidationException::class, 'expired');

    expect($club->fresh()->isGuestExpired())->toBeTrue();
    $this->get(route('public.queue', [$club, $session->public_id]))->assertNotFound();
});
