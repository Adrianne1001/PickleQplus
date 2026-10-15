<?php

use App\Events\PlaySessionChanged;
use App\Livewire\Sessions\Show;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\GuestClubService;
use App\Services\PlaySessionService;
use App\Support\GuestOwnerContext;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** The guest club's expiry as a timestamp, read fresh. */
function guestExpiry(Club $club): int
{
    return $club->fresh()->guest_expires_at->getTimestamp();
}

test('a state change extends the expiry to 24 hours from now', function () {
    [$club, $session] = guestBoard([], now()->addHour());
    $player = Player::factory()->for($club)->manual(3)->create();

    app(CheckInService::class)->checkIn($session, $player);

    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());
});

test('every kind of session change counts: settings, start, QR, TV and end', function () {
    [$club, $session] = guestBoard([], now()->addHour());
    app(GuestOwnerContext::class)->set($club);
    $sessions = app(PlaySessionService::class);

    $sessions->update($session, ['name' => 'Renamed']);
    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());

    $this->travel(2)->hours();
    $sessions->start($session);
    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());

    $this->travel(2)->hours();
    $sessions->regenerateCheckinToken($session, null);
    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());

    $this->travel(2)->hours();
    $sessions->resetTvLink($session, null);
    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());

    $this->travel(2)->hours();
    $sessions->end($session);
    expect(guestExpiry($club))->toBe(now()->addHours(24)->getTimestamp());
});

test('the expiry never goes past 72 hours after creation', function () {
    [$club, $session] = guestBoard([], now()->addHour());
    $club->forceFill(['created_at' => now()->subHours(70)])->save();

    event(new PlaySessionChanged($session->id));

    expect(guestExpiry($club))->toBe(now()->addHours(2)->getTimestamp());

    // At the cap, more activity changes nothing.
    $this->travel(1)->hours();
    $capped = guestExpiry($club);
    event(new PlaySessionChanged($session->id));
    expect(guestExpiry($club))->toBe($capped);
});

test('page views and polling do not extend the expiry', function () {
    [$club, $session, $token] = guestBoard([], now()->addHour());
    $expiry = guestExpiry($club);
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertOk();
    $this->get(route('clubs.sessions.results', [$club, $session]))->assertOk();
    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk();
    Livewire::test(Show::class, ['club' => $club, 'session' => $session])->call('syncFromBroadcast');

    expect(guestExpiry($club))->toBe($expiry);
});

test('a rolled-back action does not count as activity', function () {
    [$club, $session] = guestBoard([], now()->addHour());
    $expiry = guestExpiry($club);

    try {
        app(PlaySessionService::class)->update($session, ['courts' => 99]);
    } catch (ValidationException) {
    }

    expect(guestExpiry($club))->toBe($expiry);
});

test('an expired club stays expired when a late event arrives', function () {
    [$club, $session] = guestBoard([], now()->subMinute());
    $expiry = guestExpiry($club);

    event(new PlaySessionChanged($session->id));

    expect(guestExpiry($club))->toBe($expiry)->and($club->fresh()->isGuestExpired())->toBeTrue();
});

test('a normal club is untouched by session events', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create();

    event(new PlaySessionChanged($session->id));

    expect($club->fresh()->guest_expires_at)->toBeNull();
});

test('an event for a deleted session is ignored', function () {
    guestBoard([], now()->addHour());
    $normal = Club::factory()->create();
    $snapshot = fn () => Club::query()->orderBy('id')->get()->map(fn (Club $c) => [$c->id, $c->guest_expires_at?->getTimestamp(), $c->updated_at?->getTimestamp()])->all();
    $before = $snapshot();

    event(new PlaySessionChanged(999999));

    expect($snapshot())->toBe($before)->and($normal->fresh()->guest_expires_at)->toBeNull();
});

test('the expiry helpers follow the config', function () {
    config(['pickleq.guest.idle_hours' => 2, 'pickleq.guest.max_hours' => 3]);
    $club = Club::factory()->guest()->create();
    $service = app(GuestClubService::class);

    expect($service->expiryFromNow($club)->getTimestamp())->toBe(now()->addHours(2)->getTimestamp())
        ->and($service->hardCap($club)->getTimestamp())->toBe($club->created_at->addHours(3)->getTimestamp());

    $this->travel(2)->hours();
    expect($service->expiryFromNow($club)->getTimestamp())->toBe($club->created_at->addHours(3)->getTimestamp());
});
