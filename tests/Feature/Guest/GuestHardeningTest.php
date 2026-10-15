<?php

use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Rules\ClubSlug;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\GuestClubService;
use App\Services\PlaySessionService;
use App\Services\SelfCheckInService;
use App\Support\GuestOwnerContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

// --- slugs and the tombstone ---

test('the tombstone only speaks for a slug no club holds now', function () {
    [$owner, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();
    Cache::put('guest-gone:'.$club->slug, true, now()->addDays(7));

    $this->actingAs($owner)->get(route('clubs.sessions.show', [$club, $session]))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
});

test('a normal club cannot take a guest- slug on create or rename, but a claimed club keeps its own', function () {
    $rule = fn (string $slug, ?string $unchanged = null) => Validator::make(['slug' => $slug], ['slug' => [new ClubSlug($unchanged)]])->passes();

    expect($rule('guest-night'))->toBeFalse()
        ->and($rule('guest-'))->toBeFalse()
        ->and($rule('guestnight'))->toBeTrue()
        ->and($rule('my-guest-night'))->toBeTrue()
        ->and($rule('guest-abc123', 'guest-abc123'))->toBeTrue()
        ->and($rule('guest-other', 'guest-abc123'))->toBeFalse();

    $owner = User::factory()->create();
    $club = app(ClubService::class)->create($owner, ['name' => 'Guest Night']);
    expect($club->slug)->toBe('club-guest-night')
        ->and(ClubSlug::usesGuestPrefix($club->slug))->toBeFalse();

    expect(fn () => app(ClubService::class)->update($club, ['slug' => 'guest-night']))->toThrow(ValidationException::class, 'reserved');
    expect($club->fresh()->slug)->toBe('club-guest-night');
});

test('a claimed club can still be updated with its guest- slug', function () {
    [$club, , $token] = guestBoard();
    $owner = User::factory()->create();
    app(GuestClubService::class)->claim($club, $owner, $token);
    $slug = $club->slug;

    app(ClubService::class)->update($club, ['name' => 'Renamed', 'slug' => $slug]);

    expect($club->fresh()->name)->toBe('Renamed')->and($club->fresh()->slug)->toBe($slug);
});

// --- the circuit breaker lock ---

test('a start that cannot get the start lock gets the busy error', function () {
    config(['pickleq.guest.start_lock_wait' => 1]);
    $held = Cache::lock('guest-start', 30);
    expect($held->get())->toBeTrue();

    expect(fn () => app(GuestClubService::class)->start())->toThrow(ValidationException::class, 'very busy');
    expect(Club::query()->count())->toBe(0);

    $held->release();
    app(GuestClubService::class)->start();
    expect(Club::query()->count())->toBe(1);
    // The lock is released after a start, so the next one is not delayed.
    expect(Cache::lock('guest-start', 5)->get())->toBeTrue();
});

// --- a batched livewire update ---

test('a batched livewire update drops the guest context when the next component is from another page', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $this->withCookie(guestCookie($club), $token);

    $board = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->getContent());
    $queue = guestSnapshot($this->get(route('public.queue', [$club, $session->public_id]))->getContent());
    expect($board)->not->toBe('')->and($queue)->not->toBe('');

    $memo = new ReflectionProperty(PersistentMiddleware::class, 'middlewareAppliedFor');
    $memo->setValue(app(PersistentMiddleware::class), []);

    $this->withCredentials()->postJson(Livewire::getUpdateUri(), ['components' => [
        ['snapshot' => $board, 'updates' => [], 'calls' => [['path' => '', 'method' => 'syncFromBroadcast', 'params' => []]]],
        ['snapshot' => $queue, 'updates' => [], 'calls' => []],
    ]], ['X-Livewire' => '1'])->assertOk();

    expect(app(GuestOwnerContext::class)->club())->toBeNull();
});

test('components of the same page keep the guest context in a batch', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);
    $board = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->getContent());

    $memo = new ReflectionProperty(PersistentMiddleware::class, 'middlewareAppliedFor');
    $memo->setValue(app(PersistentMiddleware::class), []);

    $this->withCredentials()->postJson(Livewire::getUpdateUri(), ['components' => [
        ['snapshot' => $board, 'updates' => [], 'calls' => [['path' => '', 'method' => 'syncFromBroadcast', 'params' => []]]],
    ]], ['X-Livewire' => '1'])->assertOk();

    expect(app(GuestOwnerContext::class)->club()?->is($club))->toBeTrue();
});

// --- activity listener failures ---

test('a failing activity listener is reported and never breaks the action', function () {
    Exceptions::fake();
    [$club, $session] = guestBoard([], now()->addHour());
    $mock = Mockery::mock(GuestClubService::class);
    $mock->shouldReceive('expiryFromNow')->andThrow(new RuntimeException('boom'));
    app()->instance(GuestClubService::class, $mock);

    event(new PlaySessionChanged($session->id));

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
});

// --- writes to an expired, not yet purged guest club ---

test('every write path refuses an expired guest club', function () {
    [$club, $session] = guestBoard(['status' => 'live', 'started_at' => now()], now()->subMinute());
    $session->forceFill(['checkin_token' => 'tok'.str_repeat('b', 29)])->save();
    app(GuestOwnerContext::class)->set($club);
    $player = Player::factory()->for($club)->manual(3)->create();
    $sessions = app(PlaySessionService::class);
    $checkIns = app(CheckInService::class);
    $self = app(SelfCheckInService::class);

    $writes = [
        'check in' => fn () => $checkIns->checkIn($session, $player),
        'quick add' => fn () => $checkIns->addNewPlayer($session, ['name' => 'New One', 'stars' => 3]),
        'check out' => fn () => $checkIns->checkOut($session, $player),
        'break' => fn () => $checkIns->goOnBreak($session, $player),
        'return' => fn () => $checkIns->returnFromBreak($session, $player),
        'remove' => fn () => $checkIns->removeCheckIn($session, $player, null),
        'update' => fn () => $sessions->update($session, ['name' => 'Late']),
        'end' => fn () => $sessions->end($session),
        'qr' => fn () => $sessions->regenerateCheckinToken($session, null),
        'tv' => fn () => $sessions->resetTvLink($session, null),
        'self check in' => fn () => $self->checkIn($session, (string) $session->checkin_token, (string) $player->public_id, '7.7.7.7'),
        'self register' => fn () => $self->register($session, (string) $session->checkin_token, 'Walk In', null, 3, '7.7.7.7'),
        'second session' => fn () => $sessions->create($club),
    ];

    foreach ($writes as $label => $write) {
        expect($write)->toThrow(ValidationException::class, 'expired');
    }
    expect($club->players()->where('name', 'like', '%New One%')->orWhere('name', 'Walk In')->exists())->toBeFalse();
});

test('an unexpired guest club and a normal club still accept the same writes', function () {
    [$club, $session] = guestBoard();
    app(GuestOwnerContext::class)->set($club);
    $player = Player::factory()->for($club)->manual(3)->create();

    app(CheckInService::class)->checkIn($session, $player);
    app(PlaySessionService::class)->update($session, ['name' => 'On time']);

    expect($session->fresh()->name)->toBe('On time');
});
