<?php

namespace App\Services;

use App\Domain\Stats\RankedRow;
use App\Domain\Stats\Ranker;
use App\Domain\Stats\StatRow;
use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the shareable results pages (Phase 11): one session's results
 * (staff and public variants) and the public past-sessions list. Counting rules
 * are the Phase 5 ones (done matches with both scores set).
 *
 * Staff variant: numeric player ids (`id`) for profile links, void matches in
 * the log. Public variant: only `name` and `public_id`, done matches only.
 *
 * @phpstan-type ResultsRow array{rank: int|null, id?: int, public_id?: string, name: string, initials: string, played: int, wins: int, losses: int, win_pct: int, point_diff: int}
 * @phpstan-type Neighbour array{id?: int, name: string, date: Carbon, public_id: string|null}
 * @phpstan-type PlayerHighlight array{id?: int, public_id?: string, name: string, value: int}|null
 * @phpstan-type MatchHighlight array{team_a: list<string>, team_b: list<string>, score_a: int, score_b: int, margin: int}|null
 * @phpstan-type SessionResults array{
 *   session: array{id?: int, name: string, date: Carbon, status: string, courts: int, public_id: string|null, club_name: string, club_slug: string},
 *   totals: array{matches: int, players: int, points: int, courts: int},
 *   standings: list<ResultsRow>,
 *   podium: list<ResultsRow>,
 *   highlights: array{most_games: PlayerHighlight, best_point_diff: PlayerHighlight, closest_match: MatchHighlight, biggest_win: MatchHighlight},
 *   previous: Neighbour|null,
 *   next: Neighbour|null,
 *   matches: list<array<string, mixed>>
 * }
 * @phpstan-type SessionsListRow array{name: string, date: Carbon, public_id: string|null, matches: int, players: int, top_player: string|null}
 * @phpstan-type SessionsList array{live: array{name: string, public_id: string|null}|null, sessions: list<SessionsListRow>, page: int, per_page: int, total: int, last_page: int}
 */
class SessionResultsService
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly StatsService $stats,
        private readonly Ranker $ranker = new Ranker,
    ) {}

    /**
     * Staff results of a session (any status; a live session is "so far").
     *
     * @return SessionResults
     */
    public function staffResults(PlaySession $session): array
    {
        return $this->build($session, public: false);
    }

    /**
     * Cached (60 s) public results of an ended session. Null unless the session
     * belongs to the club and has ended.
     *
     * @return SessionResults|null
     */
    public function publicResults(Club $club, PlaySession $session): ?array
    {
        if ($session->club_id !== $club->id || $session->status !== SessionStatus::Ended) {
            return null;
        }

        /** @var SessionResults $data */
        $data = Cache::remember(
            "public-session-results:{$session->id}",
            StatsService::PUBLIC_CACHE_SECONDS,
            fn (): array => $this->build($session, public: true),
        );

        return $data;
    }

    /**
     * Public past-sessions list: the live session (if any) and ended sessions,
     * newest first. Cached 60 s per club and page; a fixed number of queries.
     *
     * @return SessionsList
     */
    public function publicSessions(Club $club, int $page = 1): array
    {
        // Clamp before the cache key so pages past the end share the last page's entry.
        $total = Cache::remember(
            "public-sessions-total:{$club->id}",
            StatsService::PUBLIC_CACHE_SECONDS,
            fn (): int => PlaySession::query()->where('club_id', $club->id)->where('status', SessionStatus::Ended->value)->count(),
        );
        $page = min(max(1, $page), max(1, (int) ceil($total / self::PER_PAGE)));

        /** @var SessionsList $data */
        $data = Cache::remember(
            "public-sessions:{$club->id}:{$page}",
            StatsService::PUBLIC_CACHE_SECONDS,
            fn (): array => $this->buildSessionsList($club, $page),
        );

        return $data;
    }

    // ------------------------------------------------------------------ results

    /**
     * @return SessionResults
     */
    private function build(PlaySession $session, bool $public): array
    {
        $club = $session->club ?? Club::query()->findOrFail($session->club_id);
        $standings = $this->stats->sessionStandings($session);
        $log = $this->stats->matchLog($session, includeVoid: ! $public);
        $counted = array_values(array_filter($log, fn (array $m): bool => ! $m['void'] && $m['score_a'] !== null && $m['score_b'] !== null));

        $publicPlayers = $public
            ? $this->stats->publicPlayers(array_map(fn (RankedRow $r): int => (int) $r->row->id, $standings))
            : [];

        $rows = [];
        foreach ($standings as $r) {
            $rows[] = $this->row($r, $public, $publicPlayers);
        }

        $points = 0;
        foreach ($counted as $m) {
            $points += (int) $m['score_a'] + (int) $m['score_b'];
        }

        $matches = [];
        foreach ($log as $m) {
            if ($public) {
                unset($m['id'], $m['status'], $m['void']);
            }
            $matches[] = $m;
        }

        $sessionInfo = [
            'name' => $session->name,
            'date' => $session->date,
            'status' => $session->status->value,
            'courts' => (int) $session->courts,
            'public_id' => $session->public_id,
            'club_name' => $club->name,
            'club_slug' => $club->slug,
        ];
        if (! $public) {
            $sessionInfo = ['id' => (int) $session->id] + $sessionInfo;
        }

        return [
            'session' => $sessionInfo,
            'totals' => ['matches' => count($counted), 'players' => count($standings), 'points' => $points, 'courts' => (int) $session->courts],
            'standings' => $rows,
            'podium' => $counted === [] ? [] : array_slice($rows, 0, 3),
            'highlights' => $this->highlights($rows, $counted, $public),
            'previous' => $this->neighbour($session, previous: true, public: $public),
            'next' => $this->neighbour($session, previous: false, public: $public),
            'matches' => $matches,
        ];
    }

    /**
     * @param  array<int, array{public_id: string, name: string}>  $publicPlayers
     * @return ResultsRow
     */
    private function row(RankedRow $r, bool $public, array $publicPlayers): array
    {
        $name = $r->row->name;
        $row = [
            'rank' => $r->rank,
            'name' => $name,
            'initials' => self::initials($name),
            'played' => $r->row->played,
            'wins' => $r->row->wins,
            'losses' => $r->row->losses(),
            'win_pct' => $r->row->winPercent(),
            'point_diff' => $r->row->pointDiff(),
        ];

        return $public
            ? ['rank' => $row['rank'], 'public_id' => $publicPlayers[(int) $r->row->id]['public_id'] ?? ''] + $row
            : ['rank' => $row['rank'], 'id' => (int) $r->row->id] + $row;
    }

    /** Up to two initials: first letters of the first and last words. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '?';
        }
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * @param  list<ResultsRow>  $rows
     * @param  list<array<string, mixed>>  $counted  Done, scored log rows in finish order.
     * @return array{most_games: PlayerHighlight, best_point_diff: PlayerHighlight, closest_match: MatchHighlight, biggest_win: MatchHighlight}
     */
    private function highlights(array $rows, array $counted, bool $public): array
    {
        $byName = $rows;
        usort($byName, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));

        /** @param callable(array<string, mixed>): int $metric */
        $pick = function (callable $metric) use ($byName, $public): ?array {
            $best = null;
            foreach ($byName as $row) {
                if ($best === null || $metric($row) > $metric($best)) {
                    $best = $row;
                }
            }
            if ($best === null || $metric($best) < 1) {
                return null;
            }

            return ($public ? ['public_id' => $best['public_id'] ?? ''] : ['id' => $best['id'] ?? 0]) + ['name' => $best['name'], 'value' => $metric($best)];
        };

        $closest = $biggest = null;
        foreach ($counted as $m) {
            $margin = abs((int) $m['score_a'] - (int) $m['score_b']);
            // $counted is in finish order, so strict comparisons keep the earliest on ties.
            if ($closest === null || $margin < $closest['margin']) {
                $closest = ['m' => $m, 'margin' => $margin];
            }
            if ($biggest === null || $margin > $biggest['margin']) {
                $biggest = ['m' => $m, 'margin' => $margin];
            }
        }

        $shape = fn (?array $x): ?array => $x === null ? null : [
            'team_a' => $x['m']['team_a'],
            'team_b' => $x['m']['team_b'],
            'score_a' => (int) $x['m']['score_a'],
            'score_b' => (int) $x['m']['score_b'],
            'margin' => $x['margin'],
        ];

        return [
            'most_games' => $pick(fn (array $r): int => (int) $r['played']),
            'best_point_diff' => $pick(fn (array $r): int => (int) $r['point_diff']),
            'closest_match' => $shape($closest),
            'biggest_win' => $shape($biggest),
        ];
    }

    /**
     * @return Neighbour|null
     */
    private function neighbour(PlaySession $session, bool $previous, bool $public): ?array
    {
        $date = $session->date->toDateString();
        $op = $previous ? '<' : '>';
        $query = PlaySession::query()
            ->where('club_id', $session->club_id)
            ->where('status', SessionStatus::Ended->value)
            ->where(function ($q) use ($op, $date, $session): void {
                $q->whereDate('date', $op, $date)
                    ->orWhere(fn ($q2) => $q2->whereDate('date', $date)->where('id', $op, $session->id));
            });
        if ($previous) {
            $query->orderByDesc('date')->orderByDesc('id');
        } else {
            $query->orderBy('date')->orderBy('id');
        }

        $other = $query->first(['id', 'name', 'date', 'public_id']);
        if ($other === null) {
            return null;
        }

        $out = ['name' => $other->name, 'date' => $other->date, 'public_id' => $other->public_id];

        return $public ? $out : ['id' => (int) $other->id] + $out;
    }

    // ------------------------------------------------------------ sessions list

    /**
     * @return SessionsList
     */
    private function buildSessionsList(Club $club, int $page): array
    {
        $live = PlaySession::query()
            ->where('club_id', $club->id)
            ->where('status', SessionStatus::Live->value)
            ->orderByDesc('id')
            ->first(['id', 'name', 'public_id']);

        $paginator = PlaySession::query()
            ->where('club_id', $club->id)
            ->where('status', SessionStatus::Ended->value)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'name', 'date', 'public_id'], 'page', $page);

        $ids = [];
        foreach ($paginator->items() as $s) {
            $ids[] = (int) $s->id;
        }

        $perSession = $this->rowsBySession((int) $club->id, $ids);
        $matchCounts = $this->matchCounts($ids);

        $sessions = [];
        foreach ($paginator->items() as $s) {
            $ranked = $this->ranker->session($perSession[(int) $s->id] ?? []);
            $sessions[] = [
                'name' => $s->name,
                'date' => $s->date,
                'public_id' => $s->public_id,
                'matches' => $matchCounts[(int) $s->id] ?? 0,
                'players' => count($ranked),
                'top_player' => isset($ranked[0]) ? $ranked[0]->row->name : null,
            ];
        }

        return [
            'live' => $live === null ? null : ['name' => $live->name, 'public_id' => $live->public_id],
            'sessions' => $sessions,
            'page' => $paginator->currentPage(),
            'per_page' => self::PER_PAGE,
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * Per-player totals for several sessions in one grouped query.
     *
     * @param  list<int>  $sessionIds
     * @return array<int, list<StatRow>>
     */
    private function rowsBySession(int $clubId, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $won = StatsService::WON_SQL;
        $rows = DB::table('match_players as mp')
            ->join('matches as m', 'm.id', '=', 'mp.match_id')
            ->join('play_sessions as s', 's.id', '=', 'm.play_session_id')
            ->join('players as p', 'p.id', '=', 'mp.player_id')
            ->where('s.club_id', $clubId)
            ->where('p.club_id', $clubId)
            ->whereIn('m.play_session_id', $sessionIds)
            ->where('m.status', MatchStatus::Done->value)
            ->whereNotNull('m.team_a_score')
            ->whereNotNull('m.team_b_score')
            ->groupBy('m.play_session_id', 'p.id', 'p.name')
            ->selectRaw('m.play_session_id as session_id, p.id as player_id, p.name as player_name, count(*) as played')
            ->selectRaw("sum(case when {$won} then 1 else 0 end) as wins")
            ->selectRaw("sum(case when mp.team = 'A' then m.team_a_score else m.team_b_score end) as points_for")
            ->selectRaw("sum(case when mp.team = 'A' then m.team_b_score else m.team_a_score end) as points_against")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->session_id][] = new StatRow((int) $r->player_id, (string) $r->player_name, (int) $r->played, (int) $r->wins, (int) $r->points_for, (int) $r->points_against);
        }

        return $out;
    }

    /**
     * @param  list<int>  $sessionIds
     * @return array<int, int>
     */
    private function matchCounts(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('matches')
            ->whereIn('play_session_id', $sessionIds)
            ->where('status', MatchStatus::Done->value)
            ->whereNotNull('team_a_score')
            ->whereNotNull('team_b_score')
            ->groupBy('play_session_id')
            ->selectRaw('play_session_id, count(*) as c')
            ->get();
        foreach ($rows as $r) {
            $out[(int) $r->play_session_id] = (int) $r->c;
        }

        return $out;
    }
}
