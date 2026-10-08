<?php

use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;

test('guests see the landing page with sign up and log in links', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Open play that runs itself')
        ->assertSee('data-test="hero-register"', false)
        ->assertSee(route('register'), false)
        ->assertSee(route('login'), false)
        ->assertSee('Log in')
        ->assertDontSee('Go to dashboard')
        ->assertDontSee('noindex', false);
});

test('the landing page lists every section and is honest about unfinished modes', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('id="features"', false)
        ->assertSee('id="how-it-works"', false)
        ->assertSee('id="modes"', false)
        ->assertSee('id="faq"', false)
        ->assertSee('DUPR CSV export')
        ->assertSee('Coming soon');
});

test('signed in users see a dashboard link instead of sign up', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Go to dashboard')
        ->assertSee(route('dashboard'), false)
        ->assertDontSee('data-test="hero-register"', false);
});

test('the theme toggle is on the landing page', function () {
    $this->get(route('home'))->assertOk()->assertSee('data-theme-toggle', false);
});

test('the theme toggle is in the app shell', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    $this->actingAs($user)->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee('data-theme-toggle', false);
});

test('the theme toggle is on the auth pages', function () {
    $this->get(route('login'))->assertOk()->assertSee('data-theme-toggle', false);
});

test('the theme toggle is on public pages but the TV page is forced dark', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Live]);

    $this->get('/c/'.$club->slug.'/s/'.$session->public_id)
        ->assertOk()
        ->assertSee('data-theme-toggle', false)
        ->assertSee('localStorage.getItem(KEY)', false);

    $this->get('/c/'.$club->slug.'/tv/'.$session->tv_id)
        ->assertOk()
        ->assertDontSee('data-theme-toggle', false)
        ->assertSee('<html lang="en" class="dark"', false);
});

test('light is the default theme on every layout', function () {
    $this->get(route('home'))->assertOk()->assertSee("mode = 'light'", false);
    $this->get(route('login'))->assertOk()->assertSee("mode = 'light'", false);
});
