<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\GuestClubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('guest-start:127.0.0.1');
    RateLimiter::clear('guest-link:127.0.0.1');
});

function guestCookieFrom($response, Club $club)
{
    return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === GuestClubService::cookieName($club));
}

test('one start creates one hidden club, one draft session and a token hash', function () {
    $response = $this->post(route('guest.start'), ['name' => 'Tuesday Night']);

    $club = Club::query()->sole();
    $session = PlaySession::query()->sole();

    expect($club->is_guest)->toBeTrue()
        ->and($club->name)->toBe('Guest session')
        ->and($club->slug)->toStartWith('guest-')
        ->and($club->users()->count())->toBe(0)
        ->and($club->guest_token_hash)->toHaveLength(64)
        ->and($club->guest_expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue()
        ->and($session->club_id)->toBe($club->id)
        ->and($session->status)->toBe(SessionStatus::Draft)
        ->and($session->name)->toBe('Tuesday Night')
        ->and($session->courts)->toBe(4);

    $response->assertRedirect(route('clubs.sessions.show', [$club, $session]));
    $cookie = guestCookieFrom($response, $club);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax');
});

test('the plain token is not stored and the cookie value opens the board', function () {
    $response = $this->post(route('guest.start'));
    $club = Club::query()->sole();

    $token = $response->getCookie(GuestClubService::cookieName($club))->getValue();
    expect(hash('sha256', $token))->toBe($club->guest_token_hash)
        ->and($club->guest_token_hash)->not->toBe($token);

    $session = PlaySession::query()->sole();
    $this->withCookie(GuestClubService::cookieName($club), $token)
        ->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->assertSee('Open Play');
});

test('a blank name falls back to the default session name', function () {
    $this->post(route('guest.start'), ['name' => '   '])->assertRedirect();

    expect(PlaySession::query()->sole()->name)->toBe('Open Play');
});

test('the cookie follows the session config: Secure when session.secure is true', function () {
    config(['session.secure' => true]);
    $response = $this->post(route('guest.start'));
    $club = Club::query()->sole();

    $cookie = guestCookieFrom($response, $club);
    expect($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue();
});

test('the cookie is not Secure when session.secure is off', function () {
    config(['session.secure' => false]);
    $response = $this->post(route('guest.start'));

    expect(guestCookieFrom($response, Club::query()->sole())->isSecure())->toBeFalse();
});

test('the cookie lifetime runs to the 72 hour cap', function () {
    $response = $this->post(route('guest.start'));
    $club = Club::query()->sole();

    $cookie = guestCookieFrom($response, $club);
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addHours(71)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addHours(72)->addMinute()->getTimestamp());
});

test('the sixth start in an hour is refused with a friendly error, not a bare 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('guest.start'))->assertRedirect();
    }

    $response = $this->from('/')->post(route('guest.start'));

    $response->assertRedirect('/')->assertSessionHasErrors('guest', null, 'guest');
    expect(session()->get('errors')->getBag('guest')->first('guest'))->toContain('Too many guest sessions')->toContain('minutes')
        ->and(Club::query()->count())->toBe(5);
});

test('a filled honeypot is rejected and creates nothing', function () {
    $this->post(route('guest.start'), [CreateNewUser::HONEYPOT => 'https://spam.example'])
        ->assertSessionHasErrors(CreateNewUser::HONEYPOT, null, 'guest');

    expect(Club::query()->count())->toBe(0);
});

test('a too long name is rejected', function () {
    $this->post(route('guest.start'), ['name' => str_repeat('a', 121)])->assertSessionHasErrors('name', null, 'guest');

    expect(Club::query()->count())->toBe(0);
});

test('a signed-in user is sent to create a club instead', function () {
    $this->actingAs(User::factory()->create())->post(route('guest.start'))->assertRedirect(route('clubs.create'));

    expect(Club::query()->count())->toBe(0);
});

test('the circuit breaker refuses a start when too many guest clubs are active', function () {
    config(['pickleq.guest.max_active' => 2]);
    Club::factory()->guest()->count(2)->create();
    Club::factory()->guest(null, now()->subHour())->create(); // expired ones do not count

    $this->from('/')->post(route('guest.start'))->assertRedirect('/')->assertSessionHasErrors('guest', null, 'guest');

    expect(Club::query()->count())->toBe(3);

    config(['pickleq.guest.max_active' => 3]);
    $this->post(route('guest.start'))->assertRedirectContains('/clubs/');
});

test('the organizer link sets the cookie on another device and opens the board', function () {
    [$club, $session, $token] = guestBoard();

    $response = $this->get(route('guest.enter', $token));

    $response->assertRedirect(route('clubs.sessions.show', [$club, $session]));
    $cookie = guestCookieFrom($response, $club);
    expect($response->getCookie(guestCookie($club))->getValue())->toBe($token)->and($cookie->isHttpOnly())->toBeTrue();
});

test('the organizer link with a wrong, malformed or expired token shows the expired page', function () {
    [, , $oldToken] = guestBoard([], now()->subMinute());

    $this->get(route('guest.enter', str_repeat('a', 40)))->assertRedirect(route('guest.expired'));
    $this->get('/guest/short')->assertNotFound();
    $this->get(route('guest.enter', $oldToken))->assertRedirect(route('guest.expired'));
});

test('the expired page renders', function () {
    $this->get(route('guest.expired'))->assertStatus(410)->assertSee('expired');
});

test('the organizer link is throttled', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->get(route('guest.enter', str_repeat('a', 40)))->assertRedirect();
    }

    $this->get(route('guest.enter', str_repeat('a', 40)))->assertStatus(429);
});

test('the board can read the organizer link back from the cookie', function () {
    [$club, , $token] = guestBoard();
    $service = app(GuestClubService::class);

    $request = Request::create('/', 'GET', [], [guestCookie($club) => $token]);
    expect($service->organizerUrl($club, $request))->toBe(route('guest.enter', $token))
        ->and($service->organizerUrl($club, Request::create('/')))->toBeNull();
});
