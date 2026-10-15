<?php

use App\Livewire\Guest\OrganizerLink;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear('guest-start:127.0.0.1');
    RateLimiter::clear('guest-link:127.0.0.1');
});

// --- landing page ---

test('visitors see the start as guest call to action next to sign up and log in', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-test="guest-start"', false)
        ->assertSee('Start as guest')
        ->assertSee('action="'.route('guest.start').'"', false)
        ->assertSee('name="name"', false)
        ->assertSee('name="homepage_url"', false)
        ->assertSee('data-test="hero-register"', false)
        ->assertSee('data-test="hero-login"', false);
});

test('the call to action says that the session is temporary and lists the limits', function () {
    $this->get(route('home'))
        ->assertSee('data-test="guest-start-limits"', false)
        ->assertSeeText('deleted 24 h after the last activity')
        ->assertSeeText('never kept longer than 3 days')
        ->assertSeeText('Up to 6 courts and 40 players');
});

test('a signed in user does not see the guest call to action', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-test="guest-start"', false)
        ->assertDontSee('Start as guest');
});

test('a busy guest service shows its error from the guest error bag on the landing page', function () {
    config(['pickleq.guest.max_active' => 0]);

    $this->from(route('home'))->followingRedirects()->post(route('guest.start'))
        ->assertOk()
        ->assertSee('data-test="guest-start-error"', false)
        ->assertSee('very busy right now');
    expect(Club::query()->count())->toBe(0);
});

test('starting with the form fields works end to end', function () {
    $this->post(route('guest.start'), ['name' => 'Friday night', 'homepage_url' => ''])->assertRedirect();

    expect(PlaySession::query()->sole()->name)->toBe('Friday night');
});

// --- the guest layout, banner and organizer link ---

test('the board uses the guest layout with the badge, theme toggle, claim link and no sidebar', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))
        ->assertOk()
        ->assertSee('data-test="guest-topbar"', false)
        ->assertSee('data-test="guest-badge"', false)
        ->assertSeeText('Guest session')
        ->assertSee('data-theme-toggle', false)
        ->assertSee('data-test="guest-claim-link"', false)
        ->assertSee('href="'.route('guest.claim', $club).'"', false)
        ->assertSee('Sign up to keep this session')
        ->assertDontSee('data-flux-sidebar', false)
        ->assertDontSee('club-switcher', false);
});

test('the countdown banner shows on the board, edit and results pages', function () {
    $this->freezeTime();
    [$club, $session, $token] = guestBoard([], now()->addHours(5));
    $this->withCookie(guestCookie($club), $token);

    foreach (['clubs.sessions.show', 'clubs.sessions.edit', 'clubs.sessions.results'] as $route) {
        $this->get(route($route, [$club, $session]))
            ->assertOk()
            ->assertSee('data-test="guest-banner"', false)
            ->assertSeeText('This guest session will be deleted in about 5 h')
            ->assertSeeText('up to 72 h after you started')
            ->assertSee('datetime="'.now()->addHours(5)->toIso8601String().'"', false)
            ->assertSee('data-test="guest-banner-claim"', false)
            ->assertSee('href="'.route('guest.claim', $club).'"', false);
    }
});

test('the countdown counts minutes in the last hour and turns urgent', function () {
    $this->freezeTime();
    [$club, $session, $token] = guestBoard([], now()->addMinutes(30));
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))
        ->assertSeeText('will be deleted in about 30 min')
        ->assertSee('bg-red-50', false);
});

test('the banner follows the new expiry after activity', function () {
    $this->freezeTime();
    [$club, $session, $token] = guestBoard([], now()->addHours(5));
    $this->withCookie(guestCookie($club), $token);
    $club->forceFill(['guest_expires_at' => now()->addHours(20)])->save();

    $this->get(route('clubs.sessions.show', [$club, $session]))->assertSeeText('deleted in about 20 h');
});

test('the board has a collapsed organizer link panel with the warning, QR and copy field', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))
        ->assertOk()
        ->assertSee('data-test="guest-organizer-link"', false)
        ->assertSee('x-data="{ open: false }"', false)
        ->assertSee('data-test="guest-organizer-url"', false)
        ->assertSee('value="'.route('guest.enter', $token).'"', false)
        ->assertSee('data-test="guest-organizer-qr"', false)
        ->assertSee('<svg', false)
        ->assertSeeText("Open this link on another device to run the board. Anyone with it controls this session — don't post it publicly.");
});

test('the organizer panel only shows on a device that holds the cookie', function () {
    [$club, , $token] = guestBoard();

    Livewire::test(OrganizerLink::class, ['club' => $club])->assertDontSee('guest-organizer-url');
    Livewire::withCookie(guestCookie($club), $token)->test(OrganizerLink::class, ['club' => $club])->assertSee('guest-organizer-url');
    // The organizer panel is for guest clubs only.
    Livewire::test(OrganizerLink::class, ['club' => Club::factory()->create()])->assertNotFound();
});

test('the edit page shows the guest court cap and no way to the sessions list', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $html = $this->get(route('clubs.sessions.edit', [$club, $session]))->assertOk()->getContent();

    expect($html)->toContain('max="6"')
        ->not->toContain('href="'.route('clubs.sessions.index', $club).'"')
        ->not->toContain('href="'.route('clubs.players.index', $club).'"');
});

// --- staff-only links: hidden for guests, present for members ---

test('a member keeps the staff links and sees none of the guest chrome', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->create();

    $html = $this->actingAs($owner)->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('clubs.sessions.index', $club).'"')
        ->toContain('delete-session-button')
        ->not->toContain('data-test="guest-topbar"')
        ->not->toContain('data-test="guest-banner"')
        ->not->toContain('data-test="guest-organizer-link"')
        ->not->toContain('data-guest-expiry');

    $this->actingAs($owner)->get(route('clubs.sessions.edit', [$club, $session]))
        ->assertOk()
        ->assertSee('href="'.route('clubs.sessions.index', $club).'"', false)
        ->assertSee('max="50"', false)
        ->assertDontSee('data-test="guest-banner"', false);
});

test('a guest sees none of the staff only links on the board, edit and results pages', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $staff = [
        route('clubs.sessions.index', $club),
        route('clubs.sessions.create', $club),
        route('clubs.players.index', $club),
        route('clubs.sessions.dupr', [$club, $session]),
        route('clubs.stats', $club),
        route('clubs.settings', $club),
        route('clubs.members', $club),
        route('dashboard'),
        route('public.sessions', $club),
        route('public.stats', $club),
    ];

    foreach (['draft', 'live', 'ended'] as $state) {
        $session->forceFill(match ($state) {
            'draft' => ['status' => 'draft'],
            'live' => ['status' => 'live', 'started_at' => now()],
            'ended' => ['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()],
        })->save();

        foreach (['clubs.sessions.show', 'clubs.sessions.edit', 'clubs.sessions.results'] as $route) {
            $html = $this->get(route($route, [$club, $session]))->assertOk()->getContent();

            foreach ($staff as $url) {
                expect($html)->not->toContain('href="'.$url.'"');
            }
            expect($html)->not->toContain('delete-session-button')->not->toContain('dupr-export-link')->not->toContain('data-test="all-sessions"');
        }
    }
});

// --- expired and claim pages ---

test('the expired page explains that the data was deleted and offers a new guest session or sign up', function () {
    $this->get(route('guest.expired'))
        ->assertStatus(410)
        ->assertSee('data-test="guest-expired"', false)
        ->assertSeeText('This guest session has ended and its data was deleted.')
        ->assertSee('action="'.route('guest.start').'"', false)
        ->assertSee('data-test="expired-start-guest"', false)
        ->assertSee('data-test="expired-register"', false)
        ->assertSee('href="'.route('register').'"', false)
        ->assertSee('data-test="expired-home"', false)
        ->assertSee('data-theme-toggle', false);
});

test('the expired page offers a signed in visitor the dashboard instead of a guest start', function () {
    $this->actingAs(User::factory()->create())->get(route('guest.expired'))
        ->assertStatus(410)
        ->assertDontSee('expired-start-guest', false)
        ->assertSee('expired-dashboard', false);
});

test('the claim page explains what happens and prefills the club name with the session name', function () {
    [$club, $session, $token] = guestBoard(['name' => 'Saturday ladder']);

    $this->actingAs(User::factory()->create())
        ->withCookie(guestCookie($club), $token)
        ->get(route('guest.claim', $club))
        ->assertOk()
        ->assertSee('data-test="guest-claim"', false)
        ->assertSee('data-test="claim-explainer"', false)
        ->assertSeeText('You become the owner of a new club')
        ->assertSeeText('The organizer link stops working')
        ->assertSee('action="'.route('guest.claim.store', $club).'"', false)
        ->assertSee('data-test="claim-name"', false)
        ->assertSee('value="Saturday ladder"', false)
        ->assertSee('data-test="claim-submit"', false)
        ->assertSee('href="'.route('clubs.sessions.show', [$club, $session]).'"', false);
});

// --- live expiry markup ---

test('guest pages carry the live expiry hook on the channel of their session', function () {
    [$club, $session, $token] = guestBoard();
    $session->forceFill(['status' => 'live', 'started_at' => now()])->save();
    $session->refresh();
    $channel = 'play-session.'.$session->public_id;

    $this->withCookie(guestCookie($club), $token);
    foreach (['clubs.sessions.show', 'clubs.sessions.edit', 'clubs.sessions.results'] as $route) {
        $this->get(route($route, [$club, $session]))->assertSee('data-guest-expiry="'.route('guest.expired').'"', false)->assertSee($channel, false);
    }

    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk()->assertSee('data-test="guest-expiry-watch"', false)->assertSee($channel, false);
    $this->get(route('public.tv', [$club, $session->tv_id]))->assertOk()->assertSee('data-test="guest-expiry-watch"', false)->assertSee($channel, false);
    $this->get(route('public.checkin', $session->checkin_token))->assertOk()->assertSee('data-test="guest-expiry-watch"', false)->assertSee($channel, false);
});

test("a normal club's public pages carry no guest expiry hook", function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create();

    foreach ([
        route('public.queue', [$club, $session->public_id]),
        route('public.tv', [$club, $session->tv_id]),
        route('public.checkin', $session->checkin_token),
    ] as $url) {
        $this->get($url)->assertOk()->assertDontSee('data-guest-expiry', false);
    }
});

// --- review fixes: status codes, banner, token exposure, honeypot, players ---

test('a livewire call to an expired or purged guest board is 410, but a bad cookie is a plain 404', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $this->withCookie(guestCookie($club), $token);
    $snapshot = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->getContent());

    guestAction($this, $snapshot, 'syncFromBroadcast')->assertOk();

    $club->forceFill(['guest_expires_at' => now()->subSecond()])->save();
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertStatus(410);

    // Purged: the club is gone and only the tombstone is left.
    $slug = $club->slug;
    $club->delete();
    Cache::put('guest-gone:'.$slug, true, now()->addDays(7));
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertStatus(410);
});

test('a livewire call with the wrong organizer cookie is 404, never 410', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $this->withCookie(guestCookie($club), $token);
    $snapshot = guestSnapshot($this->get(route('clubs.sessions.show', [$club, $session]))->getContent());

    $this->withCookie(guestCookie($club), ($token[0] === 'x' ? 'y' : 'x').substr($token, 1));
    guestAction($this, $snapshot, 'syncFromBroadcast')->assertNotFound();
});

test('board pages redirect on 410 only, and public pages on 404 and 410', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $session->refresh();
    $this->withCookie(guestCookie($club), $token);

    foreach (['clubs.sessions.show', 'clubs.sessions.edit', 'clubs.sessions.results'] as $route) {
        $this->get(route($route, [$club, $session]))->assertSee('data-guest-expiry-on="410"', false);
    }
    foreach ([route('public.queue', [$club, $session->public_id]), route('public.tv', [$club, $session->tv_id]), route('public.checkin', $session->checkin_token)] as $url) {
        $this->get($url)->assertSee('data-guest-expiry-on="404,410"', false);
    }
});

test('the public queue and TV of a purged guest club show the expired page, and unknown clubs stay 404', function () {
    Cache::put('guest-gone:guest-gone-slug', true, now()->addDays(7));

    $this->get('/c/guest-gone-slug/s/abc123')->assertRedirect(route('guest.expired'));
    $this->get('/c/guest-gone-slug/tv/abc123')->assertRedirect(route('guest.expired'));
    $this->get('/c/never-existed/s/abc123')->assertNotFound();
    $this->get('/c/never-existed/tv/abc123')->assertNotFound();
    // A live club that merely has no such session is still a plain 404.
    $club = Club::factory()->create();
    $this->get(route('public.queue', [$club, 'nosuchid']))->assertNotFound();
});

test('the banner is a status region that counts down in the browser and flags urgency in text', function () {
    $this->freezeTime();
    [$club, $session, $token] = guestBoard([], now()->addHours(5));
    $this->withCookie(guestCookie($club), $token);

    $calm = $this->get(route('clubs.sessions.edit', [$club, $session]))->assertOk()->getContent();
    expect($calm)->toContain('role="status"')
        ->toContain('x-data="guestCountdown(')
        ->toContain('x-bind:hidden="! urgent"');
    // Not urgent yet: the cue is rendered hidden (the browser flips it at 2 hours).
    expect(preg_match('~guest-banner-urgent"[^>]*\shidden\s*>~', $calm))->toBe(1);

    $club->forceFill(['guest_expires_at' => now()->addMinutes(45)])->save();
    $this->get(route('clubs.sessions.edit', [$club, $session]))->assertOk()
        ->assertSeeText('Deleting soon.')
        ->assertDontSee('"! urgent" hidden', false);
});

test('the top bar keeps the badge on its own row on phones and hides only the wordmark', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.show', [$club, $session]))
        ->assertSee('order-last basis-full sm:order-none', false)
        ->assertSee('sr-only', false)
        ->assertSee('data-test="guest-badge"', false);
});

test('the organizer token never leaks outside the organizer panel', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $session->refresh();
    $this->withCookie(guestCookie($club), $token);

    foreach ([
        route('public.queue', [$club, $session->public_id]),
        route('public.tv', [$club, $session->tv_id]),
        route('public.checkin', $session->checkin_token),
    ] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain($token)->not->toContain(route('guest.enter', $token));
    }

    $html = $this->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->getContent();
    // The panel itself shows it (that is its job). Everything else must not.
    expect($html)->toContain($token);
    $outside = preg_replace('~<section[^>]*data-test="guest-organizer-link".*?</section>~s', '', $html);
    expect($outside)->not->toContain($token)->not->toContain(route('guest.enter', $token));

    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snapshots);
    expect($snapshots[1])->not->toBeEmpty();
    foreach ($snapshots[1] as $snapshot) {
        expect(html_entity_decode($snapshot, ENT_QUOTES))->not->toContain($token);
    }

    foreach (['clubs.sessions.edit', 'clubs.sessions.results'] as $route) {
        expect($this->get(route($route, [$club, $session]))->assertOk()->getContent())->not->toContain($token);
    }
});

test('a filled honeypot is rejected and shown in the start error box', function () {
    $this->from(route('home'))->followingRedirects()
        ->post(route('guest.start'), ['homepage_url' => 'https://spam.example'])
        ->assertOk()
        ->assertSee('data-test="guest-start-error"', false)
        ->assertSee('This could not be completed.');

    expect(Club::query()->count())->toBe(0);
});

test('a live guest board with checked-in players has no player profile links', function () {
    [$club, $session, $token] = guestBoard(['status' => 'live', 'started_at' => now()]);
    $players = Player::factory()->count(3)->for($club)->create();
    foreach ($players as $player) {
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $player->id]);
    }
    $this->withCookie(guestCookie($club), $token);

    foreach (['clubs.sessions.show', 'clubs.sessions.results'] as $route) {
        $html = $this->get(route($route, [$club, $session]))->assertOk()->getContent();

        expect($html)->not->toContain('/clubs/'.$club->slug.'/players');
    }
});
