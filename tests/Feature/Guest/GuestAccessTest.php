<?php

use App\Enums\ClubRole;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\CheckInQr;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\ResultsPage;
use App\Livewire\Sessions\Show;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Support\GuestOwnerContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

// --- the organizer cookie opens the three board routes ---

test('the organizer cookie opens the board, edit and results pages of its own guest club', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->assertSee($session->name);
    $this->get(route('clubs.sessions.edit', [$club, $session]))->assertOk();
    $this->get(route('clubs.sessions.results', [$club, $session]))->assertOk();
});

test('the guest pages use the plain layout and show no staff links', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $html = $this->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->getContent();

    expect($html)->not->toContain('href="'.route('clubs.sessions.index', $club).'"')
        ->not->toContain('delete-session-button')
        ->not->toContain('dupr-export-link')
        ->not->toContain('club-switcher');
});

test('an ended guest session hides the DUPR link and the results page has no staff links', function () {
    [$club, $session, $token] = guestBoard();
    $session->forceFill(['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()])->save();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->assertDontSee('dupr-export-link');
    $html = $this->get(route('clubs.sessions.results', [$club, $session]))->assertOk()->getContent();
    expect($html)->not->toContain('/players/')->not->toContain('href="'.route('clubs.sessions.index', $club).'"');
});

test('no cookie, a wrong cookie or another club\'s cookie is a 404', function () {
    [$club, $session] = guestBoard();
    [$other, , $otherToken] = guestBoard();

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    $this->withCookie(guestCookie($club), str_repeat('x', 40))->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    // The other club's valid cookie, under the other club's name or replayed under this club's name.
    $this->withCookie(guestCookie($other), $otherToken)->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    $this->withCookie(guestCookie($club), $otherToken)->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
});

test('a guest of club A gets 404 on guest club B and cannot reach club B through a mismatched session', function () {
    [$a, $sessionA, $tokenA] = guestBoard();
    [$b, $sessionB] = guestBoard();
    $this->withCookie(guestCookie($a), $tokenA);

    foreach (['show', 'edit', 'results'] as $page) {
        $this->get(route("clubs.sessions.{$page}", [$b, $sessionB]))->assertNotFound();
    }
    // Club A in the URL with club B's session: scoped binding, 404.
    $this->get(route('clubs.sessions.show', [$a, $sessionB]))->assertNotFound();
});

test('a guest of club A cannot open a normal club board', function () {
    [$a, , $tokenA] = guestBoard();
    $normal = Club::factory()->withOwner()->create();
    $session = PlaySession::factory()->for($normal)->create();
    $this->withCookie(guestCookie($a), $tokenA);

    $this->get(route('clubs.sessions.show', [$normal, $session]))->assertRedirect(route('login'));
});

test('every member-only route of a guest club is closed to its organizer', function () {
    [$club, $session, $token] = guestBoard();
    $routes = [
        route('clubs.show', $club),
        route('clubs.settings', $club),
        route('clubs.members', $club),
        route('clubs.players.index', $club),
        route('clubs.stats', $club),
        route('clubs.sessions.index', $club),
        route('clubs.sessions.create', $club),
        route('clubs.sessions.dupr', [$club, $session]),
    ];

    // Not signed in: sent to log in, never shown anything.
    $this->withCookie(guestCookie($club), $token);
    foreach ($routes as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    // Signed in but not a member: 404.
    $stranger = User::factory()->create();
    foreach ($routes as $url) {
        $this->actingAs($stranger)->get($url)->assertNotFound();
    }
});

test('the guest board is also closed to a signed-in stranger without the cookie, and open with it', function () {
    [$club, $session, $token] = guestBoard();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    $this->actingAs($stranger)->withCookie(guestCookie($club), $token)->get(route('clubs.sessions.show', [$club, $session]))->assertOk();
});

test('an expired guest club sends the organizer to the expired page, even before the purge has run', function () {
    [$club, $session, $token] = guestBoard([], now()->subSecond());

    $this->withCookie(guestCookie($club), $token)
        ->get(route('clubs.sessions.show', [$club, $session]))->assertRedirect(route('guest.expired'));
    // Without the cookie nothing is revealed.
    $this->flushSession();
    $this->defaultCookies = [];
    $this->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
});

test('a purged guest club with a tombstone shows the expired page', function () {
    Cache::put('guest-gone:guest-gone-slug', true, now()->addDays(7));

    $this->get('/clubs/guest-gone-slug/sessions/1')->assertRedirect(route('guest.expired'));
    $this->get('/clubs/never-existed/sessions/1')->assertRedirect(route('login'));
});

// --- normal clubs behave exactly as before ---

test('normal clubs: guests go to login, strangers get 404, members get in', function () {
    [$owner, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();
    $stranger = User::factory()->create();

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertRedirect(route('login'));
    $this->get(route('clubs.sessions.edit', [$club, $session]))->assertRedirect(route('login'));
    $this->get(route('clubs.sessions.results', [$club, $session]))->assertRedirect(route('login'));
    $this->get('/clubs/nope/sessions/1')->assertRedirect(route('login'));
    $this->actingAs($stranger)->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    $this->actingAs($owner)->get(route('clubs.sessions.show', [$club, $session]))->assertOk();
    expect(Club::current()?->is($club))->toBeTrue();
});

test('normal clubs: the unauthenticated intended URL is kept', function () {
    [, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('clubs.sessions.show', [$club, $session]));
});

test('normal clubs: an unverified user is sent to verification when the flag is on', function () {
    $user = User::factory()->unverified()->create();
    $club = Club::factory()->withOwner($user)->create();
    $session = PlaySession::factory()->for($club)->create();

    // phpunit.xml turns email verification on.
    $this->actingAs($user)->get(route('clubs.sessions.show', [$club, $session]))->assertRedirect(route('verification.notice'));
});

test('normal clubs: staff may open the board and a cookie for the guest club does not change that', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();
    $session = PlaySession::factory()->for($club)->create();
    [$guestClub, , $token] = guestBoard();

    $this->actingAs($staff)->withCookie(guestCookie($guestClub), $token)
        ->get(route('clubs.sessions.edit', [$club, $session]))->assertOk();

    // The guest cookie unlocked nothing here: the member chain ran, so no guest context is bound
    // and the staff member gets no access to the guest club through it.
    expect(app(GuestOwnerContext::class)->club())->toBeNull()
        ->and(Club::current()?->is($club))->toBeTrue();
    $this->defaultCookies = [];
    $guestSession = PlaySession::query()->where('club_id', $guestClub->id)->firstOrFail();
    $this->actingAs($staff)->get(route('clubs.sessions.show', [$guestClub, $guestSession]))->assertNotFound();
});

// --- Gate::before whitelist ---

test('the gate whitelist grants only the allowed abilities inside the guest club', function () {
    [$club, $session] = guestBoard();
    [$other, $otherSession] = guestBoard();
    $normal = Club::factory()->create();
    $normalSession = PlaySession::factory()->for($normal)->create();
    app(GuestOwnerContext::class)->set($club);

    foreach (['view', 'update', 'manage'] as $ability) {
        expect(Gate::forUser(null)->allows($ability, $session))->toBeTrue()
            ->and(Gate::forUser(null)->allows($ability, $otherSession))->toBeFalse()
            ->and(Gate::forUser(null)->allows($ability, $normalSession))->toBeFalse();
    }
    expect(Gate::forUser(null)->allows('create', [Player::class, $club]))->toBeTrue()
        ->and(Gate::forUser(null)->allows('create', [Player::class, $other]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', [Player::class, $normal]))->toBeFalse();

    // Everything else stays closed.
    expect(Gate::forUser(null)->allows('delete', $session))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewAny', [PlaySession::class, $club]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('create', [PlaySession::class, $club]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $club))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewStats', $club))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewAny', [Player::class, $club]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', Player::factory()->for($club)->create()))->toBeFalse();
});

test('without a guest context the gate grants nothing, and it never denies a member', function () {
    [$club, $session] = guestBoard();
    expect(Gate::forUser(null)->allows('manage', $session))->toBeFalse();

    [$owner, $normal] = memberOf();
    $normalSession = PlaySession::factory()->for($normal)->create();
    app(GuestOwnerContext::class)->set($club);

    // A member's rights are untouched whether or not a guest context is bound.
    expect(Gate::forUser($owner)->allows('manage', $normalSession))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $normalSession))->toBeTrue();
});

test('the guest context is cleared by the middleware on a normal club request', function () {
    [$guestClub, , $token] = guestBoard();
    [$owner, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();
    app(GuestOwnerContext::class)->set($guestClub);

    $this->actingAs($owner)->get(route('clubs.sessions.show', [$club, $session]))->assertOk();

    expect(app(GuestOwnerContext::class)->club())->toBeNull();
});

// --- guest actions through the components and services ---

test('a guest can run the board actions: start, QR regenerate, TV reset, remove a check-in', function () {
    [$club, $session] = guestBoard();
    app(GuestOwnerContext::class)->set($club);
    $player = Player::factory()->for($club)->manual(3)->create();
    app(CheckInService::class)->checkIn($session, $player);

    $token = $session->fresh()->checkin_token;
    Livewire::test(CheckInQr::class, ['session' => $session])->call('regenerate')->call('resetTvLink');
    expect($session->fresh()->checkin_token)->not->toBe($token);

    Livewire::test(CheckInPanel::class, ['session' => $session])->call('removeCheckIn', $player->id)->assertHasNoErrors();
    expect($session->sessionPlayers()->count())->toBe(0);

    Livewire::test(Show::class, ['club' => $club, 'session' => $session])->call('start');
    expect($session->fresh()->isLive())->toBeTrue();
});

test('a guest cannot delete the session, and the edit form and quick add work', function () {
    [$club, $session] = guestBoard();
    app(GuestOwnerContext::class)->set($club);

    Livewire::test(Show::class, ['club' => $club, 'session' => $session])->call('delete')->assertForbidden();
    expect(PlaySession::query()->whereKey($session->id)->exists())->toBeTrue();

    Livewire::test(Form::class, ['club' => $club, 'session' => $session])
        ->set('name', 'Renamed')->call('save')->assertHasNoErrors();
    expect($session->fresh()->name)->toBe('Renamed');

    Livewire::test(ResultsPage::class, ['club' => $club, 'session' => $session])->assertOk();
});

test('a component of club B is forbidden to the guest of club A', function () {
    [$a] = guestBoard();
    [$b, $sessionB] = guestBoard();
    app(GuestOwnerContext::class)->set($a);

    Livewire::test(Show::class, ['club' => $b, 'session' => $sessionB])->assertForbidden();
    Livewire::test(CheckInPanel::class, ['session' => $sessionB])->assertForbidden();
});

// --- Livewire update requests re-check access ---

test('livewire update requests re-check the organizer cookie and expiry', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $snapshot = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->getContent());
    expect($snapshot)->not->toBe('');

    // Positive control.
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertOk();

    // The club expires while the page is open.
    Club::query()->whereKey($club->id)->update(['guest_expires_at' => now()->subMinute()]);
    // 410 Gone, not 404: the browser sends the guest to the expired page only on 410.
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertStatus(410);
});

test('a livewire update without the cookie is a 404', function () {
    [$club, $session, $token] = guestBoard();

    $snapshot = guestSnapshot($this->withCookie(guestCookie($club), $token)->get(route('clubs.sessions.show', [$club, $session]))->getContent());
    $this->flushSession();

    $this->defaultCookies = [];
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertNotFound();
});

test('a livewire update for a normal club still requires membership', function () {
    [$owner, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();

    $staff = User::factory()->create();
    $club->users()->attach($staff->id, ['role' => ClubRole::Staff->value]);
    $this->actingAs($staff);
    $snapshot = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->getContent());
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertOk();

    app(ClubService::class)->removeMember($club, $staff);

    guestAction($this, $snapshot, 'syncFromBroadcast')->assertNotFound();
});
