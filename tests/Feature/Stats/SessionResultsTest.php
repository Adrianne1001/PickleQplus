<?php

use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\SessionResultsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function resSession(Club $club, string $date = '2026-10-01', SessionStatus $status = SessionStatus::Ended, ?string $name = null): PlaySession
{
    return PlaySession::factory()->for($club)->create(['date' => $date, 'status' => $status] + ($name ? ['name' => $name] : []));
}

/** @return list<Player> */
function resPlayers(Club $club, string ...$names): array
{
    return array_map(fn (string $n) => Player::factory()->for($club)->create(['name' => $n]), $names);
}

/**
 * @param  list<Player>  $a
 * @param  list<Player>  $b
 */
function resMatch(PlaySession $s, array $a, array $b, ?int $sa, ?int $sb, MatchStatus $status = MatchStatus::Done, int $finishedMinutesAgo = 0): GameMatch
{
    $m = GameMatch::factory()->create([
        'play_session_id' => $s->id, 'status' => $status, 'team_a_score' => $sa, 'team_b_score' => $sb,
        'court_no' => 1, 'started_at' => now()->subMinutes(15 + $finishedMinutesAgo), 'finished_at' => now()->subMinutes($finishedMinutesAgo),
    ]);
    foreach ([[$a, 'A'], [$b, 'B']] as [$team, $letter]) {
        foreach ($team as $i => $p) {
            MatchPlayer::create(['match_id' => $m->id, 'player_id' => $p->id, 'team' => $letter, 'slot' => $i + 1]);
        }
    }

    return $m;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    Cache::flush();
});

it('computes totals, podium and highlights', function () {
    $club = Club::factory()->create(['name' => 'Dink Club']);
    $s = resSession($club, name: 'Friday Open');
    [$a, $b, $c, $d] = resPlayers($club, 'Ann Lee', 'Bob', 'Cy', 'Di');
    resMatch($s, [$a, $b], [$c, $d], 11, 9, finishedMinutesAgo: 30);
    resMatch($s, [$a, $c], [$b, $d], 11, 2, finishedMinutesAgo: 20);
    resMatch($s, [$a, $d], [$b, $c], 11, 9, finishedMinutesAgo: 10);
    resMatch($s, [$a, $b], [$c, $d], 5, 1, MatchStatus::Void);
    resMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Playing);

    $r = app(SessionResultsService::class)->staffResults($s);

    expect($r['session'])->toMatchArray(['name' => 'Friday Open', 'club_name' => 'Dink Club', 'club_slug' => $club->slug, 'id' => $s->id])
        ->and($r['totals'])->toBe(['matches' => 3, 'players' => 4, 'points' => 20 + 13 + 20, 'courts' => $s->courts])
        ->and($r['podium'])->toHaveCount(3)
        ->and($r['podium'][0])->toMatchArray(['rank' => 1, 'name' => 'Ann Lee', 'initials' => 'AL', 'played' => 3, 'wins' => 3, 'losses' => 0, 'win_pct' => 100, 'id' => $a->id])
        ->and($r['highlights']['most_games']['name'])->toBe('Ann Lee')
        ->and($r['highlights']['most_games']['value'])->toBe(3)
        ->and($r['highlights']['best_point_diff'])->toMatchArray(['name' => 'Ann Lee', 'value' => 13])
        // closest: 11-9 twice, the earliest finished wins the tie
        ->and($r['highlights']['closest_match'])->toBe(['team_a' => ['Ann Lee', 'Bob'], 'team_b' => ['Cy', 'Di'], 'score_a' => 11, 'score_b' => 9, 'margin' => 2])
        ->and($r['highlights']['biggest_win']['margin'])->toBe(9)
        ->and($r['matches'])->toHaveCount(4);
});

it('breaks highlight ties by name and by earliest finish', function () {
    $club = Club::factory()->create();
    $s = resSession($club);
    [$a, $b, $c, $d] = resPlayers($club, 'Zed', 'Amy', 'Cy', 'Di');
    // Everyone plays once; Zed/Amy tie at the top with equal diff.
    resMatch($s, [$a, $b], [$c, $d], 11, 5, finishedMinutesAgo: 20);

    $r = app(SessionResultsService::class)->staffResults($s);

    expect($r['highlights']['most_games']['name'])->toBe('Amy')
        ->and($r['highlights']['best_point_diff']['name'])->toBe('Amy')
        ->and($r['podium'][0]['rank'])->toBe(1)
        ->and($r['podium'][1]['rank'])->toBe(1)
        ->and($r['podium'][2]['rank'])->toBe(3)
        ->and($r['podium'])->toHaveCount(3);
});

it('has null highlights and an empty podium without counted matches', function () {
    $club = Club::factory()->create();
    $s = resSession($club);
    [$a, $b, $c, $d] = resPlayers($club, 'A', 'B', 'C', 'D');
    resMatch($s, [$a, $b], [$c, $d], 11, 5, MatchStatus::Void);
    resMatch($s, [$a, $b], [$c, $d], null, null, MatchStatus::Playing);

    $r = app(SessionResultsService::class)->staffResults($s);

    expect($r['podium'])->toBe([])
        ->and($r['standings'])->toBe([])
        ->and($r['totals'])->toMatchArray(['matches' => 0, 'players' => 0, 'points' => 0])
        ->and($r['highlights'])->toBe(['most_games' => null, 'best_point_diff' => null, 'closest_match' => null, 'biggest_win' => null]);
});

it('hides best point diff when nobody is above zero', function () {
    $club = Club::factory()->create();
    $s = resSession($club);
    [$a, $b, $c, $d] = resPlayers($club, 'A', 'B', 'C', 'D');
    resMatch($s, [$a, $b], [$c, $d], 11, 9, finishedMinutesAgo: 10);
    resMatch($s, [$c, $d], [$a, $b], 11, 9);

    $r = app(SessionResultsService::class)->staffResults($s);

    expect($r['standings'])->toHaveCount(4);
    foreach ($r['standings'] as $row) {
        expect($row['point_diff'])->toBe(0);
    }
    expect($r['highlights']['best_point_diff'])->toBeNull()
        ->and($r['highlights']['most_games'])->not->toBeNull();
});

it('gives a smaller podium with fewer than three players', function () {
    $club = Club::factory()->create();
    $s = resSession($club);
    [$a, $b] = resPlayers($club, 'A', 'B');
    resMatch($s, [$a], [$b], 11, 4);

    $r = app(SessionResultsService::class)->staffResults($s);

    expect($r['podium'])->toHaveCount(2)->and($r['podium'][1]['rank'])->toBe(2);
});

it('finds neighbouring ended sessions of the same club only', function () {
    $club = Club::factory()->create();
    $other = Club::factory()->create();
    $first = resSession($club, '2026-09-01', name: 'First');
    $mid1 = resSession($club, '2026-09-10', name: 'Mid1');
    $mid2 = resSession($club, '2026-09-10', name: 'Mid2');
    $last = resSession($club, '2026-09-20', name: 'Last');
    resSession($club, '2026-09-15', SessionStatus::Live, 'Live');
    resSession($club, '2026-09-12', SessionStatus::Draft, 'Draft');
    resSession($other, '2026-09-10', name: 'Foreign');
    $svc = app(SessionResultsService::class);

    expect($svc->staffResults($first)['previous'])->toBeNull()
        ->and($svc->staffResults($first)['next']['name'])->toBe('Mid1')
        ->and($svc->staffResults($mid1)['previous']['name'])->toBe('First')
        ->and($svc->staffResults($mid1)['next']['name'])->toBe('Mid2')
        ->and($svc->staffResults($mid2)['previous']['name'])->toBe('Mid1')
        ->and($svc->staffResults($mid2)['next']['name'])->toBe('Last')
        ->and($svc->staffResults($last)['next'])->toBeNull()
        ->and($svc->staffResults($last)['previous']['name'])->toBe('Mid2')
        ->and($svc->staffResults($last)['previous']['public_id'])->toBe($mid2->public_id);
});

/**
 * @param  array<mixed>  $data
 * @return list<string>
 */
function resAllKeys(array $data): array
{
    $keys = [];
    $walk = function (array $d) use (&$walk, &$keys): void {
        foreach ($d as $k => $v) {
            $keys[] = (string) $k;
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($data);

    return $keys;
}

it('exposes only names and public ids in the public variant, and is cached', function () {
    $club = Club::factory()->create();
    $s = resSession($club);
    [$a, $b, $c, $d] = resPlayers($club, 'Ann Lee', 'Bob', 'Cy', 'Di');
    $a->forceFill(['dupr_id' => 'SECRET1', 'gender' => 'woman'])->save();
    resMatch($s, [$a, $b], [$c, $d], 11, 4);
    resMatch($s, [$a, $b], [$c, $d], 3, 1, MatchStatus::Void);
    $prev = resSession($club, '2026-08-01', name: 'Earlier');
    $svc = app(SessionResultsService::class);

    $r = $svc->publicResults($club, $s);
    $json = (string) json_encode($r);

    expect($r['podium'][0]['public_id'])->toBe($a->public_id)
        ->and($r['matches'])->toHaveCount(1)
        ->and($r['previous']['name'])->toBe('Earlier')
        ->and($json)->not->toContain('SECRET1')
        ->and($json)->toContain('Ann Lee');
    foreach (resAllKeys($r) as $key) {
        expect($key)->not->toBe('id')->not->toContain('dupr')->not->toContain('rating')->not->toContain('stars')->not->toContain('gender');
    }

    resMatch($s, [$a, $b], [$c, $d], 11, 4);
    expect($svc->publicResults($club, $s)['totals']['matches'])->toBe(1)
        ->and($svc->staffResults($s)['totals']['matches'])->toBe(2);

    $live = resSession($club, status: SessionStatus::Live);
    expect($svc->publicResults($club, $live))->toBeNull()
        ->and($svc->publicResults(Club::factory()->create(), $s))->toBeNull()
        ->and($prev->id)->not->toBeNull();
});

it('lists public sessions with counts, top player, live banner and no drafts or other clubs', function () {
    $club = Club::factory()->create();
    $other = Club::factory()->create();
    $old = resSession($club, '2026-09-01', name: 'Old');
    $new = resSession($club, '2026-09-10', name: 'New');
    $live = resSession($club, '2026-09-11', SessionStatus::Live, 'Live now');
    resSession($club, '2026-09-12', SessionStatus::Draft, 'Hidden draft');
    $foreign = resSession($other, '2026-09-10', name: 'Foreign');
    [$a, $b, $c, $d] = resPlayers($club, 'Ann', 'Bob', 'Cy', 'Di');
    [$x, $y, $z, $w] = resPlayers($other, 'Xe', 'Yo', 'Zu', 'Wi');
    resMatch($new, [$a, $b], [$c, $d], 11, 5);
    resMatch($new, [$a, $c], [$b, $d], 11, 7);
    resMatch($old, [$c, $d], [$a, $b], 11, 2);
    resMatch($new, [$a, $b], [$c, $d], 5, 1, MatchStatus::Void);
    resMatch($foreign, [$x, $y], [$z, $w], 11, 0);

    $data = app(SessionResultsService::class)->publicSessions($club);

    expect($data['live'])->toBe(['name' => 'Live now', 'public_id' => $live->public_id])
        ->and($data['total'])->toBe(2)
        ->and(array_column($data['sessions'], 'name'))->toBe(['New', 'Old'])
        ->and($data['sessions'][0])->toMatchArray(['public_id' => $new->public_id, 'matches' => 2, 'players' => 4, 'top_player' => 'Ann'])
        ->and($data['sessions'][1])->toMatchArray(['matches' => 1, 'players' => 4, 'top_player' => 'Cy'])
        ->and((string) json_encode($data))->not->toContain('Foreign')->not->toContain('Xe');
});

it('paginates the sessions list 20 per page, newest first', function () {
    $club = Club::factory()->create();
    foreach (range(1, 25) as $i) {
        resSession($club, Carbon::parse('2026-01-01')->addDays($i)->toDateString(), name: "S{$i}");
    }
    $svc = app(SessionResultsService::class);

    $p1 = $svc->publicSessions($club, 1);
    $p2 = $svc->publicSessions($club, 2);

    expect($p1['sessions'])->toHaveCount(20)->and($p1['sessions'][0]['name'])->toBe('S25')
        ->and($p1['last_page'])->toBe(2)->and($p1['total'])->toBe(25)
        ->and($p2['sessions'])->toHaveCount(5)->and($p2['sessions'][4]['name'])->toBe('S1')
        ->and($p2['live'])->toBeNull()
        ->and($svc->publicSessions($club, 3)['page'])->toBe(2);
});

it('runs the same number of queries however many sessions are on the page', function () {
    $count = function (int $sessions): int {
        $club = Club::factory()->create();
        foreach (range(1, $sessions) as $i) {
            $s = resSession($club, '2026-02-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            [$a, $b, $c, $d] = resPlayers($club, "A{$i}", "B{$i}", "C{$i}", "D{$i}");
            resMatch($s, [$a, $b], [$c, $d], 11, 5);
        }
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SessionResultsService::class)->publicSessions($club);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count(3))->toBe($count(15));
});

it('clamps out-of-range pages so they reuse the last page cache entry', function () {
    $club = Club::factory()->create();
    foreach (range(1, 25) as $i) {
        resSession($club, Carbon::parse('2026-01-01')->addDays($i)->toDateString(), name: "S{$i}");
    }
    $svc = app(SessionResultsService::class);

    $svc->publicSessions($club, 2);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $far = $svc->publicSessions($club, 9999);
    $zero = $svc->publicSessions($club, -3);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($far['page'])->toBe(2)->and($far['sessions'])->toHaveCount(5)
        ->and($zero['page'])->toBe(1)
        ->and($queries)->toBe(5) // page 1 is built once (total and page 2 came from the cache)
        ->and(Cache::has('public-sessions:'.$club->id.':9999'))->toBeFalse();

    expect(app(SessionResultsService::class)->publicSessions(Club::factory()->create(), 50)['sessions'])->toBe([]);
});
