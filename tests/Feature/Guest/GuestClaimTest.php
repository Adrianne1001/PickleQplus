<?php

use App\Enums\ClubRole;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\GuestClubService;
use App\Services\GuestPurgeService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;

function claimFortifyFeatures(bool $emailVerification): void
{
    config(['fortify.features' => array_values(array_filter([
        Features::registration(),
        Features::resetPasswords(),
        $emailVerification ? Features::emailVerification() : null,
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
    ]))]);
}

beforeEach(fn () => RateLimiter::clear('guest-link:127.0.0.1'));

// --- the claim page ---

test('the claim page needs the organizer cookie of that club', function () {
    [$club, , $token] = guestBoard();
    [$other, , $otherToken] = guestBoard();
    $normal = Club::factory()->withOwner()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('guest.claim', $club))->assertNotFound();
    $this->actingAs($user)->withCookie(guestCookie($club), 'x'.substr($token, 1))->get(route('guest.claim', $club))->assertNotFound();
    $this->actingAs($user)->withCookie(guestCookie($other), $otherToken)->get(route('guest.claim', $club))->assertNotFound();
    $this->actingAs($user)->withCookie(guestCookie($normal), $token)->get(route('guest.claim', $normal))->assertNotFound();
});

test('a logged-out organizer is sent to register and the claim URL is the intended one', function () {
    [$club, , $token] = guestBoard();

    $this->withCookie(guestCookie($club), $token)->get(route('guest.claim', $club))->assertRedirect(route('register'));

    expect(session('url.intended'))->toBe(route('guest.claim', $club));
});

test('a signed-in organizer sees the confirm page, an expired club goes to the expired page', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    [$old, , $oldToken] = guestBoard([], now()->subMinute());
    $user = User::factory()->create();

    $this->actingAs($user)->withCookie(guestCookie($club), $token)->get(route('guest.claim', $club))->assertOk()->assertSee('claim-submit', false);
    $this->actingAs($user)->withCookie(guestCookie($old), $oldToken)->get(route('guest.claim', $old))->assertRedirect(route('guest.expired'));
});

// --- claiming ---

test('a signed-in verified user claims the club: owner, columns cleared, rename, current club, cookie forgotten', function () {
    claimFortifyFeatures(true);
    [$club, $session, $token] = guestBoard();
    $user = User::factory()->create();
    $slug = $club->slug;

    $response = $this->actingAs($user)->withCookie(guestCookie($club), $token)
        ->post(route('guest.claim.store', $club), ['name' => 'Tuesday Crew']);

    $response->assertRedirect(route('clubs.sessions.show', [$club, $session]));
    $club->refresh();
    expect($club->is_guest)->toBeFalse()
        ->and($club->guest_token_hash)->toBeNull()
        ->and($club->guest_expires_at)->toBeNull()
        ->and($club->guest_claimed_at)->not->toBeNull()
        ->and($club->name)->toBe('Tuesday Crew')
        ->and($club->slug)->toBe($slug)
        ->and($user->fresh()->roleIn($club))->toBe(ClubRole::Owner)
        ->and($user->fresh()->current_club_id)->toBe($club->id);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === guestCookie($club));
    expect($cookie->getExpiresTime())->toBeLessThan(time());

    // It is a normal club now: the owner reaches the board without a guest cookie, the old link is dead.
    $this->flushSession();
    $this->defaultCookies = [];
    $this->actingAs($user)->get(route('clubs.sessions.show', [$club, $session]))->assertOk();
    $this->get(route('guest.enter', $token))->assertRedirect(route('guest.expired'));
});

test('a blank name keeps the club name', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    $user = User::factory()->create();

    $this->actingAs($user)->withCookie(guestCookie($club), $token)->post(route('guest.claim.store', $club), ['name' => '  '])->assertRedirect();

    expect($club->fresh()->name)->toBe('Guest session');
});

test('claiming through register, with verification off, ends on the claim page and claims', function () {
    claimFortifyFeatures(false);
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token)->get(route('guest.claim', $club))->assertRedirect(route('register'));

    $this->post(route('register.store'), [
        'name' => 'New Owner', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('guest.claim', $club));

    $this->get(route('guest.claim', $club))->assertOk();
    $this->post(route('guest.claim.store', $club))->assertRedirect(route('clubs.sessions.show', [$club, $session]));

    expect(User::query()->where('email', 'new@example.com')->firstOrFail()->roleIn($club))->toBe(ClubRole::Owner);
});

test('claiming through register, with verification on, goes through verification and comes back', function () {
    claimFortifyFeatures(true);
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token)->get(route('guest.claim', $club))->assertRedirect(route('register'));

    $this->post(route('register.store'), [
        'name' => 'New Owner', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('guest.claim', $club));
    $user = User::query()->where('email', 'new@example.com')->firstOrFail();

    // Unverified: sent to verify, and the claim page is kept as the intended URL.
    $this->get(route('guest.claim', $club))->assertRedirect(route('verification.notice'));
    expect(session('url.intended'))->toBe(route('guest.claim', $club));
    $this->post(route('guest.claim.store', $club))->assertRedirect(route('verification.notice'));
    expect($club->fresh()->is_guest)->toBeTrue();

    $user->markEmailAsVerified();
    $this->actingAs($user->fresh());
    $this->get(route('guest.claim', $club))->assertOk();
    $this->post(route('guest.claim.store', $club))->assertRedirect(route('clubs.sessions.show', [$club, $session]));
    expect($user->fresh()->roleIn($club))->toBe(ClubRole::Owner);
});

test('claiming through login returns to the claim page', function () {
    claimFortifyFeatures(true);
    [$club, $session, $token] = guestBoard();
    $user = User::factory()->create(['password' => 'password']);
    $this->withCookie(guestCookie($club), $token)->get(route('guest.claim', $club))->assertRedirect(route('register'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('guest.claim', $club));

    $this->get(route('guest.claim', $club))->assertOk();
    $this->post(route('guest.claim.store', $club))->assertRedirect(route('clubs.sessions.show', [$club, $session]));
    expect($user->fresh()->roleIn($club))->toBe(ClubRole::Owner);
});

// --- rejections ---

test('the owned-club cap rejects a claim', function () {
    claimFortifyFeatures(false);
    config(['pickleq.max_owned_clubs' => 1]);
    [$club, , $token] = guestBoard();
    $user = User::factory()->create();
    Club::factory()->withOwner($user)->create();

    expect(fn () => app(GuestClubService::class)->claim($club, $user, $token))->toThrow(ValidationException::class);
    expect($club->fresh()->is_guest)->toBeTrue();

    $this->actingAs($user)->withCookie(guestCookie($club), $token)
        ->from(route('guest.claim', $club))->post(route('guest.claim.store', $club))
        ->assertSessionHasErrors('claim');
});

test('an expired club cannot be claimed', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard([], now()->subMinute());
    $user = User::factory()->create();

    expect(fn () => app(GuestClubService::class)->claim($club, $user, $token))->toThrow(ValidationException::class);
    expect($club->fresh()->is_guest)->toBeTrue()->and($club->users()->count())->toBe(0);
});

test('a wrong token cannot claim, in the service or over HTTP', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    $user = User::factory()->create();

    expect(fn () => app(GuestClubService::class)->claim($club, $user, str_repeat('z', 40)))->toThrow(ValidationException::class);
    $this->actingAs($user)->post(route('guest.claim.store', $club))->assertNotFound();
    $this->actingAs($user)->withCookie(guestCookie($club), str_repeat('z', 40))->post(route('guest.claim.store', $club))->assertNotFound();
    expect($club->fresh()->is_guest)->toBeTrue();
    expect($token)->not->toBeEmpty();
});

test('a second claim fails and the first owner keeps the club', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    $first = User::factory()->create();
    $second = User::factory()->create();

    app(GuestClubService::class)->claim($club, $first, $token);

    expect(fn () => app(GuestClubService::class)->claim($club, $second, $token))->toThrow(ValidationException::class);
    expect($club->fresh()->users()->pluck('users.id')->all())->toBe([$first->id]);

    // Over HTTP the old cookie no longer matches, so the second claimant gets a 404.
    $this->actingAs($second)->withCookie(guestCookie($club), $token)->post(route('guest.claim.store', $club))->assertNotFound();
});

test('an unverified user cannot claim when the verification flag is on', function () {
    claimFortifyFeatures(true);
    [$club, , $token] = guestBoard();
    $user = User::factory()->unverified()->create();

    expect(fn () => app(GuestClubService::class)->claim($club, $user, $token))->toThrow(ValidationException::class);
    expect($club->fresh()->is_guest)->toBeTrue();
});

test('with verification off an unverified user can claim', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    $user = User::factory()->unverified()->create();

    app(GuestClubService::class)->claim($club, $user, $token);

    expect($user->fresh()->roleIn($club))->toBe(ClubRole::Owner);
});

test('an over-long new name is rejected', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard();
    $user = User::factory()->create();

    $this->actingAs($user)->withCookie(guestCookie($club), $token)
        ->post(route('guest.claim.store', $club), ['name' => str_repeat('a', 121)])->assertSessionHasErrors('name');
    expect($club->fresh()->is_guest)->toBeTrue();
});

// --- claim vs purge ---

test('a claimed club is never purged, even with an old expiry', function () {
    claimFortifyFeatures(false);
    [$club, , $token] = guestBoard([], now()->addMinute());
    $user = User::factory()->create();
    app(GuestClubService::class)->claim($club, $user, $token);
    $this->travel(3)->days();

    expect(app(GuestPurgeService::class)->purgeExpired())->toBe(0)
        ->and(Club::query()->whereKey($club->id)->exists())->toBeTrue()
        ->and(PlaySession::query()->where('club_id', $club->id)->count())->toBe(1);
});

test('claiming then activity does not touch expiry columns', function () {
    claimFortifyFeatures(false);
    [$club, $session, $token] = guestBoard();
    app(GuestClubService::class)->claim($club, User::factory()->create(), $token);

    event(new PlaySessionChanged($session->id));

    expect($club->fresh()->guest_expires_at)->toBeNull();
});
