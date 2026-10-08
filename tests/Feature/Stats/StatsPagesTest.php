<?php

use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Livewire\Players\Show as PlayerShow;
use App\Livewire\Public\Queue;
use App\Livewire\Sessions\Index as SessionsIndex;
use App\Livewire\Sessions\ResultsPage;
use App\Livewire\Stats\Leaderboard;
use App\Livewire\Stats\PublicLeaderboard;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: User, 2: Club, 3: list<Player>}
 */
function pagesClub(array $clubAttrs = []): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create($clubAttrs + ['leaderboard_min_games' => 1]);
    $players = [
        Player::factory()->for($club)->create(['name' => 'Jane Doe', 'dupr_id' => 'DUPR12345']),
        Player::factory()->for($club)->create(['name' => 'Bob Smith']),
        Player::factory()->for($club)->create(['name' => 'Carl Jones']),
        Player::factory()->for($club)->create(['name' => 'Dina Lee']),
    ];

    return [$owner, $staff, $club, $players];
}

/** @param list<Player> $p */
function pagesMatch(PlaySession $session, array $p, int $sa = 11, int $sb = 5, MatchStatus $status = MatchStatus::Done): GameMatch
{
    $m = GameMatch::factory()->create([
        'play_session_id' => $session->id,
        'status' => $status,
        'team_a_score' => $sa,
        'team_b_score' => $sb,
        'court_no' => 1,
        'started_at' => now()->subMinutes(15),
        'finished_at' => now(),
    ]);
    foreach ([[$p[0], 'A', 1], [$p[1], 'A', 2], [$p[2], 'B', 1], [$p[3], 'B', 2]] as [$pl, $team, $slot]) {
        MatchPlayer::create(['match_id' => $m->id, 'player_id' => $pl->id, 'team' => $team, 'slot' => $slot]);
    }

    return $m;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

// --- results page ---

test('results page shows standings and a match log with void rows', function () {
    [$owner, $staff, $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Live, 'date' => '2026-10-15']);
    pagesMatch($session, $p);
    pagesMatch($session, $p, 3, 11, MatchStatus::Void);

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get(route('clubs.sessions.results', [$club, $session]))
            ->assertOk()
            ->assertSee('Standings so far')
            ->assertSee('Jane Doe')
            ->assertSee('Bob Smith')
            ->assertSee(route('clubs.players.show', [$club, $p[0]]), false);
    }

    Livewire::actingAs($owner)->test(ResultsPage::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('data-void="1"')
        ->assertSee('void');

    $session->forceFill(['status' => SessionStatus::Ended])->save();
    $this->actingAs($owner)->get(route('clubs.sessions.results', [$club, $session]))->assertSee('Final standings');
});

test('results page 404s for non-members and other clubs sessions', function () {
    [$owner, , $club] = pagesClub();
    $session = PlaySession::factory()->for($club)->create();
    $other = Club::factory()->withOwner($owner)->create();

    $this->actingAs(User::factory()->create())->get(route('clubs.sessions.results', [$club, $session]))->assertNotFound();
    $this->actingAs($owner)->get(route('clubs.sessions.results', [$other, $session]))->assertNotFound();
});

// --- sessions list ---

test('sessions list filters by status, paginates and links ended rows to results', function () {
    [$owner, , $club, $p] = pagesClub();
    $ended = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'name' => 'Ended night', 'date' => '2025-01-01']);
    pagesMatch($ended, $p);
    PlaySession::factory()->for($club)->create(['status' => SessionStatus::Draft, 'name' => 'Draft night']);

    Livewire::actingAs($owner)->test(SessionsIndex::class, ['club' => $club])
        ->assertSee('Ended night')->assertSee('Draft night')
        ->assertSeeHtml(route('clubs.sessions.results', [$club, $ended]))
        ->assertSee('1 match')
        ->set('status', 'ended')
        ->assertSee('Ended night')->assertDontSee('Draft night')
        ->set('status', 'draft')
        ->assertSee('Draft night')->assertDontSee('Ended night');

    PlaySession::factory()->for($club)->count(22)->create(['status' => SessionStatus::Ended, 'date' => '2026-01-01']);
    Livewire::actingAs($owner)->test(SessionsIndex::class, ['club' => $club])
        ->assertDontSee('Ended night')
        ->call('gotoPage', 2)
        ->assertSee('Ended night');
});

// --- leaderboard ---

test('staff leaderboard renders for members, links profiles, and the period changes results', function () {
    [$owner, $staff, $club, $p] = pagesClub(['leaderboard_min_games' => 2]);
    $old = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2025-01-10']);
    $new = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2026-10-10']);
    pagesMatch($old, $p);
    pagesMatch($new, $p);

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get(route('clubs.stats', $club))->assertOk()->assertSee('Leaderboard')->assertSee('Jane Doe');
    }

    Livewire::actingAs($owner)->test(Leaderboard::class, ['club' => $club])
        ->assertSee('Jane Doe')->assertDontSee('Not ranked yet')
        ->assertSeeHtml(route('clubs.players.show', [$club, $p[0]]))
        ->set('period', 'this_month')
        ->assertSee('Not ranked yet')
        ->assertSee('Fewer than 2 games')
        ->set('period', 'bogus')
        ->assertDontSee('Not ranked yet');
});

test('staff leaderboard 404s for non-members', function () {
    [, , $club] = pagesClub();

    $this->actingAs(User::factory()->create())->get(route('clubs.stats', $club))->assertNotFound();
});

test('public leaderboard is always public and shows names as entered, no DUPR ids and no links', function () {
    [, , $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2026-10-10']);
    pagesMatch($session, $p);

    $response = $this->get(route('public.stats', $club))->assertOk()->assertSee('Bob Smith')->assertSee('Jane Doe');
    $response->assertDontSee('DUPR12345')
        ->assertDontSee('/clubs/'.$club->slug.'/players', false);

    Livewire::test(PublicLeaderboard::class, ['club' => $club])
        ->assertSee('Jane Doe')
        ->set('period', 'this_year')
        ->assertSee('Jane Doe');
});

// --- profile ---

test('player profile renders record, partners, opponents and history for members', function () {
    [$owner, $staff, $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2026-10-10', 'name' => 'Friday Open']);
    pagesMatch($session, $p);

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get(route('clubs.players.show', [$club, $p[0]]))
            ->assertOk()->assertSee('Jane Doe')->assertSee('DUPR12345')->assertSee('Bob Smith')->assertSee('Friday Open')
            ->assertSee(route('clubs.sessions.results', [$club, $session]), false);
    }

    Livewire::actingAs($owner)->test(PlayerShow::class, ['club' => $club, 'player' => $p[0]])
        ->assertSeeHtml('data-test="record-wl">1 - 0')
        ->set('period', 'this_month')
        ->assertSeeHtml('data-test="record-wl">1 - 0')
        ->set('period', 'this_year')
        ->assertSee('Friday Open');

    Livewire::actingAs($owner)->test(PlayerShow::class, ['club' => $club, 'player' => $p[0]])
        ->set('period', 'last_30_days')
        ->assertSee('Friday Open');

    $old = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2025-02-02', 'name' => 'Ancient Open']);
    pagesMatch($old, $p, 4, 11);
    Livewire::actingAs($owner)->test(PlayerShow::class, ['club' => $club, 'player' => $p[0]])
        ->assertSee('Ancient Open')
        ->set('period', 'this_year')
        ->assertDontSee('Ancient Open')
        ->assertSee('Friday Open');
});

test('player profile shows empty state and 404s for strangers and other clubs players', function () {
    [$owner, , $club, $p] = pagesClub();
    $other = Club::factory()->withOwner($owner)->create();
    $foreign = Player::factory()->for($other)->create();

    $this->actingAs($owner)->get(route('clubs.players.show', [$club, $p[0]]))->assertOk()->assertSee('No finished matches in this period.');
    $this->actingAs(User::factory()->create())->get(route('clubs.players.show', [$club, $p[0]]))->assertNotFound();
    $this->actingAs($owner)->get(route('clubs.players.show', [$club, $foreign]))->assertNotFound();
});

test('roster rows link to the profile and the sidebar has a Stats link', function () {
    [$owner, , $club, $p] = pagesClub();

    $this->actingAs($owner)->get(route('clubs.players.index', $club))
        ->assertSee(route('clubs.players.show', [$club, $p[0]]), false)
        ->assertSee(route('clubs.stats', $club), false);
});

// --- public ended page ---

test('public ended page always shows standings and the done-only match log', function () {
    [, , $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2026-10-10']);
    pagesMatch($session, $p);
    pagesMatch($session, $p, 2, 11, MatchStatus::Void);

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])
        ->assertSee('Session ended')
        ->assertSee('Final standings')->assertSee('Bob Smith')->assertSee('Jane Doe')
        ->assertDontSee('void')
        ->assertSeeHtml('data-test="public-match-log"');
});

// --- settings ---

test('owners save the leaderboard minimum and the settings page has no public stats switch', function () {
    [$owner, , $club] = pagesClub();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->assertDontSeeHtml('data-test="public-stats"')
        ->set('leaderboard_min_games', '25')
        ->call('saveStatsSettings')
        ->assertHasNoErrors();

    expect($club->fresh()->leaderboard_min_games)->toBe(25);
});

test('stats settings validate the minimum games range', function () {
    [$owner, , $club] = pagesClub();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->set('leaderboard_min_games', '101')
        ->call('saveStatsSettings')
        ->assertHasErrors('leaderboard_min_games');

    expect($club->fresh()->leaderboard_min_games)->toBe(1);
});

test('staff cannot open or save stats settings', function () {
    [, $staff, $club] = pagesClub();

    $this->actingAs($staff)->get(route('clubs.settings', $club))->assertForbidden();
    Livewire::actingAs($staff)->test('pages::clubs.settings', ['club' => $club])->assertForbidden();
    expect($club->fresh()->leaderboard_min_games)->toBe(1);
});

test('a live session shows no standings or match log', function () {
    [, , $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Live, 'date' => '2026-10-15']);
    pagesMatch($session, $p);

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])
        ->assertDontSee('Final standings')
        ->assertDontSeeHtml('data-test="public-standings"')
        ->assertDontSeeHtml('data-test="public-match-log"')
        ->assertSeeHtml('wire:poll');
});

test('the ended public page does not poll', function () {
    [, , $club, $p] = pagesClub();
    $session = PlaySession::factory()->for($club)->create(['status' => SessionStatus::Ended, 'date' => '2026-10-10']);
    pagesMatch($session, $p);

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])
        ->assertSee('Final standings')
        ->assertDontSeeHtml('wire:poll');
});
