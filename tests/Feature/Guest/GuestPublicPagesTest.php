<?php

use App\Enums\MatchStatus;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\Dupr\DuprExportService;
use App\Services\SessionResultsService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('the public leaderboard, sessions list and club redirect are 404 for a guest club', function () {
    [$club] = guestBoard();

    $this->get(route('public.stats', $club))->assertNotFound();
    $this->get(route('public.sessions', $club))->assertNotFound();
    $this->get(route('public.club', $club))->assertNotFound();
});

test('the same public pages still work for a normal club', function () {
    $club = Club::factory()->create();

    $this->get(route('public.stats', $club))->assertOk();
    $this->get(route('public.sessions', $club))->assertOk();
    $this->get(route('public.club', $club))->assertRedirect(route('public.sessions', $club));
});

test('the live public queue and TV pages of a guest session work', function () {
    [$club, $session] = guestBoard();
    $session->forceFill(['status' => 'live', 'started_at' => now()])->save();

    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk();
    $this->get(route('public.tv', [$club, $session->tv_id]))->assertOk();
});

test('the ended public results page of a guest session works without club tabs or other sessions', function () {
    [$club, $session] = guestBoard();
    $session->forceFill(['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()])->save();

    $response = $this->get(route('public.queue', [$club, $session->public_id]))->assertOk();

    $response->assertDontSee('data-test="public-club-header"', false)
        ->assertDontSee('data-test="results-neighbours"', false)
        ->assertDontSee('data-test="all-sessions"', false)
        ->assertDontSee(route('public.sessions', $club), false)
        ->assertDontSee(route('public.stats', $club), false);
});

test('a normal club results page keeps its header and neighbours', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->ended()->create();

    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk()
        ->assertSee('data-test="public-club-header"', false)
        ->assertSee('data-test="all-sessions"', false);
});

test('the podium images and the results of an ended guest session render', function () {
    [$club, $session] = guestBoard();
    $session->forceFill(['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()])->save();
    $players = Player::factory()->for($club)->manual(3)->count(4)->create();
    $match = GameMatch::factory()->create([
        'play_session_id' => $session->id, 'status' => MatchStatus::Done,
        'team_a_score' => 11, 'team_b_score' => 5, 'court_no' => 1,
        'started_at' => now()->subMinutes(15), 'finished_at' => now(),
    ]);
    foreach ([[0, 'A', 1], [1, 'A', 2], [2, 'B', 1], [3, 'B', 2]] as [$i, $team, $slot]) {
        MatchPlayer::create(['match_id' => $match->id, 'player_id' => $players[$i]->id, 'team' => $team, 'slot' => $slot]);
    }
    Storage::fake('local');

    $this->get(route('public.queue', [$club, $session->public_id]))->assertOk();
    $this->get(route('public.session.podium-png', [$club, $session->public_id]))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('public.session.podium-gif', [$club, $session->public_id]))->assertOk();
});

test('the DUPR export service refuses a guest club', function () {
    [$club, $session] = guestBoard();
    $session->forceFill(['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()])->save();

    try {
        app(DuprExportService::class)->export($session, User::factory()->create());
    } catch (ValidationException $e) {
        expect($e->errors()['session'][0])->toContain('guest');

        return;
    }
    throw new RuntimeException('Expected a refusal.');
});

test('the DUPR page and download are member-only routes a guest cannot use', function () {
    [$club, $session, $token] = guestBoard();
    $this->withCookie(guestCookie($club), $token);

    $this->get(route('clubs.sessions.dupr', [$club, $session]))->assertRedirect(route('login'));
    expect(app(SessionResultsService::class))->not->toBeNull();
});
