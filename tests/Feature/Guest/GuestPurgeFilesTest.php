<?php

use App\Events\SessionExpired;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\ClubCleanup;
use App\Services\GuestPurgeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| P14.10 purge file checks
|--------------------------------------------------------------------------
| GuestPurgeTest covers the single-session happy path. These cover every session of a club,
| neighbours that must stay untouched (including ids that share a prefix), the tombstone
| lifetime, and a purge that keeps going when a later step fails.
*/

/** Podium images (several versions) and caches for one session id. */
function putPodiumFiles(int $sessionId): array
{
    $paths = [
        "podium/{$sessionId}/abc-v1.png",
        "podium/{$sessionId}/abc-v1.gif",
        "podium/{$sessionId}/def-v2.png",
    ];
    foreach ($paths as $path) {
        Storage::disk('local')->put($path, 'img');
    }
    Cache::put("public-session-results:{$sessionId}", ['x'], 600);

    return $paths;
}

test('the purge removes the podium files of every session of the club, and nothing else', function () {
    Storage::fake('local');
    Event::fake([SessionExpired::class]);

    // An expired guest club that somehow holds three sessions (a draft, a live one and an ended one).
    $club = Club::factory()->guest(null, now()->subMinute())->create(['id' => 7]);
    $sessions = [
        PlaySession::factory()->for($club)->create(['id' => 7]),
        PlaySession::factory()->for($club)->live()->create(['id' => 8]),
        PlaySession::factory()->for($club)->ended()->create(['id' => 9]),
    ];
    $purgedFiles = [];
    foreach ($sessions as $session) {
        $purgedFiles = [...$purgedFiles, ...putPodiumFiles($session->id)];
    }
    Storage::disk('local')->put("dupr-exports/{$club->id}/export.csv", 'csv');

    // Neighbours: a normal club, an unexpired guest club, a claimed club (expiry in the past but cleared of guest state)
    // and sessions whose ids start with the purged ids (70 vs 7), which a prefix delete would wrongly take.
    $normal = Club::factory()->withOwner()->create(['id' => 70]);
    $normalSession = PlaySession::factory()->for($normal)->ended()->create(['id' => 70]);
    $active = Club::factory()->guest()->create(['id' => 71]);
    $activeSession = PlaySession::factory()->for($active)->create(['id' => 80]);
    $claimed = Club::factory()->withOwner()->create(['id' => 72, 'guest_claimed_at' => now()->subDay(), 'guest_expires_at' => now()->subDay()]);
    $claimedSession = PlaySession::factory()->for($claimed)->ended()->create(['id' => 90]);

    $keptFiles = [];
    foreach ([$normalSession, $activeSession, $claimedSession] as $session) {
        $keptFiles = [...$keptFiles, ...putPodiumFiles($session->id)];
    }
    foreach ([$normal, $active, $claimed] as $other) {
        Storage::disk('local')->put("dupr-exports/{$other->id}/export.csv", 'csv');
    }

    expect(app(GuestPurgeService::class)->purgeExpired())->toBe(1);

    $disk = Storage::disk('local');
    foreach ($purgedFiles as $path) {
        expect($disk->exists($path))->toBeFalse("{$path} should be gone");
    }
    foreach ($sessions as $session) {
        expect($disk->exists("podium/{$session->id}"))->toBeFalse()
            ->and(Cache::has("public-session-results:{$session->id}"))->toBeFalse();
    }
    expect($disk->exists("dupr-exports/{$club->id}/export.csv"))->toBeFalse();

    foreach ($keptFiles as $path) {
        expect($disk->exists($path))->toBeTrue("{$path} must be untouched");
    }
    foreach ([$normalSession, $activeSession, $claimedSession] as $session) {
        expect(Cache::has("public-session-results:{$session->id}"))->toBeTrue();
    }
    foreach ([$normal, $active, $claimed] as $other) {
        expect($disk->exists("dupr-exports/{$other->id}/export.csv"))->toBeTrue()
            ->and(Club::query()->whereKey($other->id)->exists())->toBeTrue();
    }
});

test('the guest-gone tombstone lasts seven days and only for the purged slug', function () {
    $this->travelTo(now()->startOfSecond());
    Storage::fake('local');
    Event::fake([SessionExpired::class]);
    $gone = Club::factory()->guest(null, now()->subMinute())->create();
    $active = Club::factory()->guest()->create();

    app(GuestPurgeService::class)->purgeExpired();

    expect(Cache::has('guest-gone:'.$gone->slug))->toBeTrue()
        ->and(Cache::has('guest-gone:'.$active->slug))->toBeFalse();

    $this->travel(6)->days();
    expect(Cache::has('guest-gone:'.$gone->slug))->toBeTrue();

    $this->travel(2)->days();
    expect(Cache::has('guest-gone:'.$gone->slug))->toBeFalse();
});

test('a failing broadcast for one club does not stop the purge of the next', function () {
    Storage::fake('local');
    $failures = 0;
    Event::listen(SessionExpired::class, function () use (&$failures): void {
        $failures++;

        throw new RuntimeException('Reverb is down');
    });
    Exceptions::fake();

    $files = [];
    $clubs = [];
    foreach ([1, 2] as $i) {
        $club = Club::factory()->guest(null, now()->subMinute())->create();
        $session = PlaySession::factory()->for($club)->create();
        $files[] = putPodiumFiles($session->id);
        $clubs[] = $club;
    }

    expect(app(GuestPurgeService::class)->purgeExpired())->toBe(2)
        ->and($failures)->toBe(2)
        ->and(Club::query()->count())->toBe(0);

    foreach ($clubs as $i => $club) {
        expect(Cache::has('guest-gone:'.$club->slug))->toBeTrue();
        foreach ($files[$i] as $path) {
            expect(Storage::disk('local')->exists($path))->toBeFalse();
        }
    }
    // The failures were reported, not swallowed silently.
    Exceptions::assertReported(RuntimeException::class);
});

test('a failing file cleanup is reported but the tombstone and the broadcast still happen', function () {
    Event::fake([SessionExpired::class]);
    Exceptions::fake();
    $this->mock(ClubCleanup::class, function ($mock): void {
        $mock->shouldReceive('afterDelete')->andThrow(new RuntimeException('disk unavailable'));
    });
    $club = Club::factory()->guest(null, now()->subMinute())->create();
    $session = PlaySession::factory()->for($club)->create();

    expect(app(GuestPurgeService::class)->purge($club->id))->toBeTrue()
        ->and(Club::query()->whereKey($club->id)->exists())->toBeFalse()
        ->and(Cache::has('guest-gone:'.$club->slug))->toBeTrue();

    Event::assertDispatched(SessionExpired::class, fn (SessionExpired $e) => $e->publicId === $session->public_id);
    Exceptions::assertReported(RuntimeException::class);
});
