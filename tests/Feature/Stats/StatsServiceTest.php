<?php

use App\Domain\Stats\RankedRow;
use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\ClubService;
use App\Services\SessionResultsService;
use App\Services\StatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @param array<string, mixed> $attrs */
function statsClub(array $attrs = []): Club
{
    return Club::factory()->create($attrs);
}

/** @param array<string, mixed> $attrs */
function statsPlayer(Club $club, string $name, array $attrs = []): Player
{
    return Player::factory()->for($club)->create(['name' => $name] + $attrs);
}

/**
 * @param  array{0: Player, 1: Player}  $a
 * @param  array{0: Player, 1: Player}  $b
 * @param  array<string, mixed>  $attrs
 */
function statsMatch(PlaySession $session, array $a, array $b, ?int $sa = 11, ?int $sb = 5, MatchStatus $status = MatchStatus::Done, array $attrs = []): GameMatch
{
    $m = GameMatch::factory()->create($attrs + [
        'play_session_id' => $session->id,
        'status' => $status,
        'team_a_score' => $sa,
        'team_b_score' => $sb,
        'court_no' => 1,
        'started_at' => now()->subMinutes(15),
        'finished_at' => now(),
    ]);
    foreach ([[$a, 'A'], [$b, 'B']] as [$team, $letter]) {
        foreach ($team as $i => $p) {
            MatchPlayer::create(['match_id' => $m->id, 'player_id' => $p->id, 'team' => $letter, 'slot' => $i + 1]);
        }
    }

    return $m;
}

function statsSession(Club $club, string $date = '2026-10-01', SessionStatus $status = SessionStatus::Ended): PlaySession
{
    return PlaySession::factory()->for($club)->create(['date' => $date, 'status' => $status]);
}

/**
 * @param  list<RankedRow>  $rows
 * @return list<string>
 */
function standingNames(array $rows): array
{
    return array_map(fn (RankedRow $r): string => $r->row->name.':'.$r->rank, $rows);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

it('computes session standings from done scored matches only', function () {
    $club = statsClub();
    $s = statsSession($club);
    [$a, $b, $c, $d] = [statsPlayer($club, 'Ann'), statsPlayer($club, 'Bob'), statsPlayer($club, 'Cy'), statsPlayer($club, 'Di')];

    statsMatch($s, [$a, $b], [$c, $d], 11, 5);
    statsMatch($s, [$a, $c], [$b, $d], 4, 11);
    statsMatch($s, [$a, $b], [$c, $d], 11, 0, MatchStatus::Void);
    statsMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Playing);
    statsMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Staged);
    statsMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Done);

    $rows = app(StatsService::class)->sessionStandings($s);

    expect(standingNames($rows))->toBe(['Bob:1', 'Di:2', 'Ann:3', 'Cy:4'])
        ->and($rows[0]->row->played)->toBe(2)
        ->and($rows[0]->row->wins)->toBe(2)
        ->and($rows[0]->row->pointsFor)->toBe(22)
        ->and($rows[0]->row->pointsAgainst)->toBe(9)
        ->and($rows[2]->row->wins)->toBe(1)
        ->and($rows[2]->row->pointDiff())->toBe(-1);
});

it('never counts another club or another session in a session standing', function () {
    $club = statsClub();
    $other = statsClub();
    $s = statsSession($club);
    $s2 = statsSession($club);
    $os = statsSession($other);
    [$a, $b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n), ['A', 'B', 'C', 'D']);
    [$e, $f, $g, $h] = array_map(fn ($n) => statsPlayer($other, $n), ['E', 'F', 'G', 'H']);

    statsMatch($s, [$a, $b], [$c, $d]);
    statsMatch($s2, [$a, $b], [$c, $d]);
    statsMatch($os, [$e, $f], [$g, $h]);

    $rows = app(StatsService::class)->sessionStandings($s);

    expect($rows)->toHaveCount(4)->and($rows[0]->row->played)->toBe(1);
});

it('builds the leaderboard with minimum games, inactive players excluded and club isolation', function () {
    $club = statsClub(['leaderboard_min_games' => 2]);
    $other = statsClub();
    $s = statsSession($club);
    $os = statsSession($other);
    [$a, $b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n), ['Ann', 'Bob', 'Cy', 'Di']);
    $gone = statsPlayer($club, 'Gone', ['active' => false]);

    statsMatch($s, [$a, $b], [$c, $d], 11, 5);
    statsMatch($s, [$a, $gone], [$c, $d], 11, 6);
    statsMatch($s, [$b, $gone], [$a, $d], 11, 2, MatchStatus::Void);
    foreach (range(1, 5) as $i) {
        statsMatch($os, [statsPlayer($other, 'X'.$i), statsPlayer($other, 'Y'.$i)], [statsPlayer($other, 'P'.$i), statsPlayer($other, 'Q'.$i)]);
    }

    $board = app(StatsService::class)->leaderboard($club);
    $names = collect([...$board['ranked'], ...$board['unranked']])->map(fn (RankedRow $r) => $r->row->name)->all();

    expect($board['min_games'])->toBe(2)
        ->and(standingNames($board['ranked']))->toBe(['Ann:1', 'Cy:2', 'Di:2'])
        ->and(standingNames($board['unranked']))->toBe(['Bob:'])
        ->and($names)->not->toContain('Gone')
        ->and($names)->toHaveCount(4);
});

it('filters the leaderboard by period using the session date', function () {
    $club = statsClub(['leaderboard_min_games' => 1]);
    [$a, $b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n), ['A', 'B', 'C', 'D']);
    foreach (['2026-10-10', '2026-09-20', '2026-03-01', '2025-12-31'] as $date) {
        statsMatch(statsSession($club, $date), [$a, $b], [$c, $d]);
    }
    $played = fn (StatsPeriod $p) => app(StatsService::class)->leaderboard($club, $p)['ranked'][0]->row->played;

    expect($played(StatsPeriod::AllTime))->toBe(4)
        ->and($played(StatsPeriod::ThisMonth))->toBe(1)
        ->and($played(StatsPeriod::Last30Days))->toBe(2)
        ->and($played(StatsPeriod::ThisYear))->toBe(3);
});

it('serves a cached, always-public leaderboard with names as entered and public ids only', function () {
    $club = statsClub(['leaderboard_min_games' => 1]);
    $s = statsSession($club);
    $a = statsPlayer($club, 'Adrianne Basuel', ['dupr_id' => 'ABC123']);
    $b = statsPlayer($club, 'Rocky');
    $c = statsPlayer($club, 'Cy Young', ['dupr_id' => 'ZZZ999']);
    $d = statsPlayer($club, 'Di Prince');
    statsMatch($s, [$a, $b], [$c, $d]);

    $service = app(StatsService::class);
    $data = $service->publicLeaderboard($club);
    $json = (string) json_encode($data);

    expect($data['ranked'])->toHaveCount(4)
        ->and($json)->toContain('Adrianne Basuel')->toContain('Rocky')->toContain((string) $a->public_id)
        ->and($json)->not->toContain('ABC123')->not->toContain('ZZZ999')
        ->and(array_keys($data['ranked'][0]))->toBe(['rank', 'id', 'name', 'played', 'wins', 'losses', 'win_pct', 'point_diff']);

    foreach ($data['ranked'] as $row) {
        expect($row['id'])->toBeString()->not->toMatch('/^\d+$/');
    }

    // Cached for 60 seconds, per club and period.
    statsMatch($s, [$a, $b], [$c, $d]);
    expect($service->publicLeaderboard($club)['ranked'][0]['played'])->toBe(1)
        ->and($service->publicLeaderboard($club, StatsPeriod::ThisYear)['ranked'][0]['played'])->toBe(2);
});

it('lists the match log in finish order and includes void matches only on request', function () {
    $club = statsClub();
    $s = statsSession($club);
    [$a, $b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n), ['Ann', 'Bob', 'Cy', 'Di']);

    $late = statsMatch($s, [$a, $b], [$c, $d], 11, 9, attrs: ['finished_at' => now(), 'court_no' => 2]);
    $early = statsMatch($s, [$c, $d], [$a, $b], 11, 3, attrs: ['finished_at' => now()->subMinutes(30), 'started_at' => now()->subMinutes(45)]);
    $void = statsMatch($s, [$a, $b], [$c, $d], 2, 1, MatchStatus::Void, ['finished_at' => now()->subHour()]);
    statsMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Playing);

    $log = app(StatsService::class)->matchLog($s);

    expect(array_column($log, 'id'))->toBe([$early->id, $late->id])
        ->and($log[0]['duration_minutes'])->toBe(15)
        ->and($log[0]['team_a'])->toBe(['Cy', 'Di'])
        ->and($log[0]['score_a'])->toBe(11)
        ->and($log[1]['court'])->toBe(2);

    $withVoid = app(StatsService::class)->matchLog($s, includeVoid: true);

    expect(array_column($withVoid, 'id'))->toBe([$early->id, $late->id, $void->id])
        ->and(array_column($withVoid, 'void'))->toBe([false, false, true]);
});

it('exposes the public results read only when ended', function () {
    $club = statsClub();
    $s = statsSession($club);
    $a = statsPlayer($club, 'Adrianne Basuel', ['dupr_id' => 'ABC123']);
    [$b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n.' Last'), ['Bo', 'Cy', 'Di']);
    statsMatch($s, [$a, $b], [$c, $d]);
    statsMatch($s, [$a, $b], [$c, $d], 11, 1, MatchStatus::Void);
    $service = app(StatsService::class);

    $data = app(SessionResultsService::class)->publicResults($club, $s);

    expect($data)->not->toBeNull();
    $json = (string) json_encode($data);

    expect($data['standings'])->toHaveCount(4)
        ->and($data['matches'])->toHaveCount(1)
        ->and($data['matches'][0]['team_a'])->toBe(['Adrianne Basuel', 'Bo Last'])
        ->and($json)->not->toContain('ABC123')
        ->and($data['standings'][0]['public_id'])->toBe($a->public_id);

    $live = statsSession($club, status: SessionStatus::Live);
    $other = statsClub();

    $results = app(SessionResultsService::class);

    expect($results->publicResults($club, $live))->toBeNull()
        ->and($results->publicResults($other, $s))->toBeNull();
});

it('builds a player profile with record, partners and opponents', function () {
    $club = statsClub();
    $s1 = statsSession($club, '2026-10-01');
    $s2 = statsSession($club, '2026-10-08');
    [$me, $p, $q, $r] = array_map(fn ($n) => statsPlayer($club, $n), ['Me', 'Pat', 'Quinn', 'Rae']);

    statsMatch($s1, [$me, $p], [$q, $r], 11, 5);
    statsMatch($s1, [$me, $p], [$q, $r], 4, 11);
    statsMatch($s2, [$q, $me], [$p, $r], 11, 9);
    statsMatch($s2, [$me, $p], [$q, $r], 11, 0, MatchStatus::Void);
    statsMatch($s2, [$me, $p], [$q, $r], null, null, MatchStatus::Playing);

    $profile = app(StatsService::class)->profile($club, $me);

    expect($profile['record'])->toMatchArray([
        'played' => 3, 'wins' => 2, 'losses' => 1, 'win_pct' => 67,
        'points_for' => 26, 'points_against' => 25, 'point_diff' => 1, 'sessions_attended' => 2,
    ])
        ->and($profile['record']['last_played']?->toDateString())->toBe('2026-10-08')
        ->and($profile['partners'])->toBe([
            ['player_id' => $p->id, 'name' => 'Pat', 'games' => 2, 'wins' => 1, 'win_pct' => 50],
            ['player_id' => $q->id, 'name' => 'Quinn', 'games' => 1, 'wins' => 1, 'win_pct' => 100],
        ])
        ->and($profile['opponents'])->toBe([
            ['player_id' => $r->id, 'name' => 'Rae', 'games' => 3, 'wins' => 2, 'losses' => 1],
            ['player_id' => $q->id, 'name' => 'Quinn', 'games' => 2, 'wins' => 1, 'losses' => 1],
            ['player_id' => $p->id, 'name' => 'Pat', 'games' => 1, 'wins' => 1, 'losses' => 0],
        ]);
});

it('returns an empty profile for a player with no games and 404s a foreign player', function () {
    $club = statsClub();
    $other = statsClub();
    $loner = statsPlayer($club, 'Loner', ['active' => false]);
    $foreign = statsPlayer($other, 'Foreign');
    $service = app(StatsService::class);

    $profile = $service->profile($club, $loner);

    expect($profile['record']['played'])->toBe(0)
        ->and($profile['record']['win_pct'])->toBeNull()
        ->and($profile['record']['last_played'])->toBeNull()
        ->and($profile['partners'])->toBe([]);
    expect(fn () => $service->profile($club, $foreign))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $service->matchHistory($club, $foreign))->toThrow(NotFoundHttpException::class);
});

it('does not count matches from another club played by the same-named profile', function () {
    $club = statsClub();
    $other = statsClub();
    [$me, $p, $q, $r] = array_map(fn ($n) => statsPlayer($club, $n), ['Me', 'Pat', 'Quinn', 'Rae']);
    // A match in another club's session that (wrongly) includes this club's player must not count.
    statsMatch(statsSession($other), [$me, $p], [$q, $r]);

    expect(app(StatsService::class)->profile($club, $me)['record']['played'])->toBe(0);
});

it('filters the profile and history by period', function () {
    $club = statsClub();
    [$me, $p, $q, $r] = array_map(fn ($n) => statsPlayer($club, $n), ['Me', 'Pat', 'Quinn', 'Rae']);
    statsMatch(statsSession($club, '2026-10-10'), [$me, $p], [$q, $r]);
    statsMatch(statsSession($club, '2025-05-01'), [$me, $p], [$q, $r]);
    $service = app(StatsService::class);

    expect($service->profile($club, $me)['record']['played'])->toBe(2)
        ->and($service->profile($club, $me, StatsPeriod::ThisYear)['record']['played'])->toBe(1)
        ->and($service->matchHistory($club, $me, StatsPeriod::ThisYear)->total())->toBe(1);
});

it('paginates match history newest first with the score from the player side', function () {
    $club = statsClub();
    [$me, $p, $q, $r] = array_map(fn ($n) => statsPlayer($club, $n), ['Me', 'Pat', 'Quinn', 'Rae']);
    $old = statsSession($club, '2026-09-01');
    $new = statsSession($club, '2026-10-01');
    statsMatch($old, [$q, $r], [$me, $p], 11, 7);
    foreach (range(1, 24) as $i) {
        statsMatch($new, [$me, $p], [$q, $r], 11, $i % 9);
    }

    $service = app(StatsService::class);
    $page1 = $service->matchHistory($club, $me);
    $first = $page1->items()[0];

    expect($page1->total())->toBe(25)->and($page1->perPage())->toBe(20)->and($page1->items())->toHaveCount(20)
        ->and($first['session_id'])->toBe($new->id)
        ->and($first['partner'])->toBe('Pat')
        ->and($first['opponents'])->toBe(['Quinn', 'Rae'])
        ->and($first['won'])->toBeTrue();

    request()->merge(['page' => 2]);
    $second = $service->matchHistory($club, $me);
    $last = $second->items()[count($second->items()) - 1];

    expect($second->items())->toHaveCount(5)
        ->and($last['session_id'])->toBe($old->id)
        ->and($last['score_for'])->toBe(7)->and($last['score_against'])->toBe(11)->and($last['won'])->toBeFalse()
        ->and($last['date']->toDateString())->toBe('2026-09-01');
});

it('lists sessions paginated with a status filter, live and draft first, with counts', function () {
    $club = statsClub();
    $other = statsClub();
    $ended = collect(range(1, 22))->map(fn ($i) => statsSession($club, '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)));
    $live = statsSession($club, '2026-01-01', SessionStatus::Live);
    $draft = statsSession($club, '2026-01-02', SessionStatus::Draft);
    statsSession($other, '2026-10-01', SessionStatus::Live);
    [$a, $b, $c, $d] = array_map(fn ($n) => statsPlayer($club, $n), ['A', 'B', 'C', 'D']);
    $target = $ended->first();
    statsMatch($target, [$a, $b], [$c, $d]);
    statsMatch($target, [$a, $b], [$c, $d], 1, 0, MatchStatus::Void);
    foreach ([$a, $b, $c] as $i => $pl) {
        SessionPlayer::query()->forceCreate(['play_session_id' => $target->id, 'player_id' => $pl->id, 'status' => $i === 2 ? 'left' : 'waiting']);
    }
    $service = app(StatsService::class);

    $page = $service->sessionsList($club);

    expect($page->total())->toBe(24)->and($page->items())->toHaveCount(20)
        ->and($page->items()[0]->id)->toBe($live->id)
        ->and($page->items()[1]->id)->toBe($draft->id);

    $filtered = $service->sessionsList($club, SessionStatus::Ended);

    expect($filtered->total())->toBe(22)
        ->and(collect($filtered->items())->every(fn ($s) => $s->status === SessionStatus::Ended))->toBeTrue();

    $live2 = $service->sessionsList($club, SessionStatus::Live);
    expect($live2->total())->toBe(1);

    request()->merge(['page' => 2]);
    $p2 = $service->sessionsList($club);
    $row = collect($p2->items())->firstWhere('id', $target->id);

    expect($p2->items())->toHaveCount(4)
        ->and($row)->not->toBeNull()
        ->and($row->done_matches_count)->toBe(1)
        ->and($row->players_count)->toBe(3)
        ->and($row->checked_in_count)->toBe(2);
});

it('lets any member view stats and denies non-members as not found', function () {
    $club = statsClub();
    $staff = User::factory()->create();
    $club->users()->attach($staff->id, ['role' => 'staff']);
    User::flushRoleCache();
    $outsider = User::factory()->create();

    expect(Gate::forUser($staff)->allows('viewStats', $club))->toBeTrue()
        ->and(Gate::forUser($outsider)->inspect('viewStats', $club)->status())->toBe(404);
});

it('saves stats settings through the club service with validation', function () {
    $club = statsClub();
    $service = app(ClubService::class);

    $service->updateStatsSettings($club, ['leaderboard_min_games' => '25']);
    $club->refresh();

    expect($club->leaderboard_min_games)->toBe(25)
        ->and(statsClub()->leaderboard_min_games)->toBe(10);

    foreach ([0, 101, 'x'] as $bad) {
        expect(fn () => $service->updateStatsSettings($club, ['leaderboard_min_games' => $bad]))->toThrow(ValidationException::class);
    }
});

it('never serves one club cached public rows to another club', function () {
    $a = statsClub(['leaderboard_min_games' => 1]);
    $b = statsClub(['leaderboard_min_games' => 1]);
    $sa = statsSession($a);
    $sb = statsSession($b);
    [$a1, $a2, $a3, $a4] = array_map(fn ($n) => statsPlayer($a, $n), ['Alpha One', 'Alpha Two', 'Alpha Three', 'Alpha Four']);
    [$b1, $b2, $b3, $b4] = array_map(fn ($n) => statsPlayer($b, $n), ['Beta One', 'Beta Two', 'Beta Three', 'Beta Four']);
    statsMatch($sa, [$a1, $a2], [$a3, $a4]);
    statsMatch($sb, [$b1, $b2], [$b3, $b4]);
    $service = app(StatsService::class);

    $service->publicLeaderboard($a);
    $results = app(SessionResultsService::class);
    $results->publicResults($a, $sa);
    $boardB = (string) json_encode($service->publicLeaderboard($b));
    $endedB = (string) json_encode($results->publicResults($b, $sb));

    expect($boardB)->toContain('Beta One')->not->toContain('Alpha')
        ->and($endedB)->toContain('Beta One')->not->toContain('Alpha')
        ->and($results->publicResults($b, $sa))->toBeNull();
});

it('returns the name as entered on staff standings and leaderboard rows', function () {
    $club = statsClub(['leaderboard_min_games' => 1]);
    $s = statsSession($club);
    [$a, $b, $c, $d] = [statsPlayer($club, 'Annie'), statsPlayer($club, 'Bob Stone'), statsPlayer($club, 'Cy'), statsPlayer($club, 'Di')];
    statsMatch($s, [$a, $b], [$c, $d]);
    $service = app(StatsService::class);

    expect($service->sessionStandings($s)[0]->row->name)->toBe('Annie')
        ->and($service->leaderboard($club)['ranked'][0]->row->name)->toBe('Annie')
        ->and($service->leaderboard($club)['ranked'][1]->row->name)->toBe('Bob Stone');
});
