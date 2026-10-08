<?php

use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Livewire\Sessions\ResultsPage;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** @return array{0: User, 1: Club, 2: list<Player>} */
function shareClub(string $name = 'Dink Club'): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create(['name' => $name, 'leaderboard_min_games' => 1]);
    $players = [];
    foreach (['Jane Doe', 'Bob Smith', 'Carl Jones', 'Dina Lee'] as $n) {
        $players[] = Player::factory()->for($club)->create(['name' => $n]);
    }

    return [$owner, $club, $players];
}

/** @param list<Player> $p */
function shareMatch(PlaySession $s, array $p, int $sa = 11, int $sb = 5, MatchStatus $status = MatchStatus::Done): void
{
    $m = GameMatch::factory()->create([
        'play_session_id' => $s->id, 'status' => $status, 'team_a_score' => $sa, 'team_b_score' => $sb,
        'court_no' => 1, 'started_at' => now()->subMinutes(15), 'finished_at' => now(),
    ]);
    foreach ([[$p[0], 'A', 1], [$p[1], 'A', 2], [$p[2], 'B', 1], [$p[3], 'B', 2]] as [$pl, $team, $slot]) {
        MatchPlayer::create(['match_id' => $m->id, 'player_id' => $pl->id, 'team' => $team, 'slot' => $slot]);
    }
}

function shareSession(Club $club, string $date, string $name, SessionStatus $status = SessionStatus::Ended): PlaySession
{
    return PlaySession::factory()->for($club)->create(['date' => $date, 'name' => $name, 'status' => $status]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

// --- staff page ---

test('staff results page shows the podium, highlights and share panel for an ended session', function () {
    [$owner, $club, $p] = shareClub();
    $session = shareSession($club, '2026-10-10', 'Friday Open');
    shareMatch($session, $p);

    $this->actingAs($owner)->get(route('clubs.sessions.results', [$club, $session]))
        ->assertOk()
        ->assertSeeHtml('data-test="podium"')
        ->assertSeeHtml('data-test="results-highlights"')
        ->assertSeeHtml('data-test="share-panel"')
        ->assertSeeHtml('data-test="download-gif"')
        ->assertSeeHtml('data-test="download-image"')
        ->assertSeeHtml('data-test="gif-preview"')
        ->assertSeeHtml('data-test="share-facebook"')
        ->assertSeeHtml('data-test="share-whatsapp"')
        ->assertSee(route('public.queue', [$club, $session->public_id]), false)
        ->assertSee(route('public.session.podium-gif', [$club, $session->public_id]).'?download=1', false)
        ->assertSee('Final standings')
        ->assertSee(route('clubs.players.show', [$club, $p[0]]), false);
});

test('staff results page for a live session shows a note instead of the share panel and keeps void rows', function () {
    [$owner, $club, $p] = shareClub();
    $session = shareSession($club, '2026-10-15', 'Live night', SessionStatus::Live);
    shareMatch($session, $p);
    shareMatch($session, $p, 3, 11, MatchStatus::Void);

    Livewire::actingAs($owner)->test(ResultsPage::class, ['club' => $club, 'session' => $session])
        ->assertSee('Standings so far')
        ->assertSeeHtml('data-test="share-after-end"')
        ->assertSee('Sharing opens once the session ends')
        ->assertDontSeeHtml('data-test="share-panel"')
        ->assertSeeHtml('data-void="1"');
});

test('staff results page links the neighbouring sessions and shows an empty podium without matches', function () {
    [$owner, $club] = shareClub();
    $old = shareSession($club, '2026-10-01', 'Old night');
    $mid = shareSession($club, '2026-10-08', 'Mid night');
    $new = shareSession($club, '2026-10-12', 'New night');

    $this->actingAs($owner)->get(route('clubs.sessions.results', [$club, $mid]))
        ->assertOk()
        ->assertSeeHtml('data-test="podium-empty"')
        ->assertDontSeeHtml('data-test="share-gif"')
        ->assertSeeHtml('data-test="previous-session"')
        ->assertSee(route('clubs.sessions.results', [$club, $old]), false)
        ->assertSee(route('clubs.sessions.results', [$club, $new]), false);
});

// --- public ended page ---

test('public ended page shows the podium, link preview tags, noindex, neighbours and no void matches', function () {
    [, $club, $p] = shareClub();
    $old = shareSession($club, '2026-10-01', 'Old night');
    $session = shareSession($club, '2026-10-10', 'Friday Open');
    $new = shareSession($club, '2026-10-12', 'New night');
    shareMatch($session, $p);
    shareMatch($session, $p, 2, 11, MatchStatus::Void);

    $png = route('public.session.podium-png', [$club, $session->public_id]);

    $this->get(route('public.queue', [$club, $session->public_id]))
        ->assertOk()
        ->assertSeeHtml('data-test="podium"')
        ->assertSeeHtml('data-test="share-panel"')
        ->assertSee('Friday Open')
        ->assertSeeHtml('<meta property="og:title" content="Friday Open · Dink Club results" />')
        ->assertSeeHtml('<meta property="og:image" content="'.$png.'" />')
        ->assertSeeHtml('<meta name="twitter:card" content="summary_large_image" />')
        ->assertSeeHtml('<meta property="og:url" content="'.route('public.queue', [$club, $session->public_id]).'" />')
        ->assertSee('🥇', false)
        ->assertSee('noindex', false)
        ->assertSeeHtml('data-test="previous-session"')
        ->assertSee(route('public.queue', [$club, $old->public_id]), false)
        ->assertSee(route('public.queue', [$club, $new->public_id]), false)
        ->assertSee(route('public.sessions', $club), false)
        ->assertDontSee('void')
        ->assertDontSeeHtml('data-void');
});

test('public ended page without counted matches has no podium image links', function () {
    [, $club] = shareClub();
    $session = shareSession($club, '2026-10-10', 'Quiet night');

    $this->get(route('public.queue', [$club, $session->public_id]))
        ->assertOk()
        ->assertSeeHtml('data-test="podium-empty"')
        ->assertDontSee('podium.png', false)
        ->assertDontSeeHtml('data-test="download-gif"');
});

// --- sessions page ---

test('public sessions page lists ended sessions only, hides other clubs and shows the live banner', function () {
    [, $club, $p] = shareClub();
    [, $other] = shareClub('Other Club');
    $ended = shareSession($club, '2026-10-10', 'Ended night');
    shareMatch($ended, $p);
    $live = shareSession($club, '2026-10-15', 'Live night', SessionStatus::Live);
    shareSession($club, '2026-10-16', 'Draft night', SessionStatus::Draft);
    shareSession($other, '2026-10-10', 'Foreign night');

    $this->get(route('public.sessions', $club))
        ->assertOk()
        ->assertSee('Ended night')
        ->assertSee('1 match')
        ->assertSeeHtml('data-test="top-player"')
        ->assertSee(route('public.queue', [$club, $ended->public_id]), false)
        ->assertSeeHtml('data-test="live-banner"')
        ->assertSee(route('public.queue', [$club, $live->public_id]), false)
        ->assertDontSee('Draft night')
        ->assertDontSee('Foreign night')
        ->assertSeeHtml('data-test="tab-leaderboard"')
        ->assertSee('noindex', false);
});

test('public sessions page shows an empty state and 404s for unknown clubs', function () {
    [, $club] = shareClub();

    $this->get(route('public.sessions', $club))->assertOk()->assertSeeHtml('data-test="sessions-empty"')->assertDontSeeHtml('data-test="live-banner"');
    $this->get('/c/no-such-club/sessions')->assertNotFound();
});

test('public sessions page paginates with a page query string', function () {
    [, $club] = shareClub();
    shareSession($club, '2025-01-01', 'Oldest night');
    foreach (range(1, 20) as $i) {
        shareSession($club, '2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Night '.$i);
    }

    $this->get(route('public.sessions', $club))
        ->assertOk()
        ->assertDontSee('Oldest night')
        ->assertSee('Page 1 of 2')
        ->assertSee('?page=2', false);

    $this->get(route('public.sessions', $club).'?page=2')
        ->assertOk()
        ->assertSee('Oldest night')
        ->assertDontSee('Night 20')
        ->assertSeeHtml('data-test="page-prev"');

    $this->get(route('public.sessions', $club).'?page=9')->assertOk()->assertSee('Oldest night');
});

test('the club url redirects to the sessions page', function () {
    [, $club] = shareClub();

    $this->get('/c/'.$club->slug)->assertRedirect(route('public.sessions', $club));
    $this->get('/c/no-such-club')->assertNotFound();
});

// --- leaderboard ---

test('public leaderboard has the club tabs', function () {
    [, $club, $p] = shareClub();
    $session = shareSession($club, '2026-10-10', 'Friday Open');
    shareMatch($session, $p);

    $this->get(route('public.stats', $club))
        ->assertOk()
        ->assertSeeHtml('data-test="public-club-header"')
        ->assertSeeHtml('data-test="tab-sessions"')
        ->assertSee(route('public.sessions', $club), false)
        ->assertSee('Jane Doe')
        ->assertSee('noindex', false);
});

test('hostile player and session names are escaped on the public ended page', function () {
    [, $club, $players] = shareClub();
    $evil = '"><script>alert(1)</script>\' & ';
    $players[0]->update(['name' => $evil.'A']);
    $session = shareSession($club, '2026-10-10', $evil.'Night');
    shareMatch($session, $players);

    $html = $this->get(route('public.queue', [$club, $session->public_id]))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toMatch('/<meta property="og:title" content="[^"<>]*&quot;&gt;&lt;script&gt;/')
        ->and($html)->toMatch('/<meta name="twitter:title" content="[^"<>]*&quot;&gt;&lt;script&gt;/')
        ->and($html)->toMatch('/<meta property="og:description" content="[^"<>]*&quot;&gt;&lt;script&gt;/')
        ->and($html)->toMatch('/<meta name="twitter:description" content="[^"<>]*&quot;&gt;&lt;script&gt;/')
        // @js() output is JSON with < escapes, never a raw tag.
        ->and($html)->toContain('navigator.share(')
        ->and($html)->toContain('\u003Cscript\u003Ealert(1)\u003C\/script\u003E');

    // Share links carry the (urlencoded) shareUrl/text only; none may break out of the href.
    preg_match_all('/<a href="([^"]*)"[^>]*data-test="share-/', $html, $hrefs);
    foreach ($hrefs[1] as $href) {
        expect($href)->not->toContain('<')->not->toContain('>')->not->toContain("'");
    }
});
