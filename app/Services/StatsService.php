<?php

namespace App\Services;

use App\Domain\Stats\RankedRow;
use App\Domain\Stats\Ranker;
use App\Domain\Stats\StatRow;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only stats (Phase 5). Only done matches with both scores set count; the
 * source of truth is matches/match_players. Staff methods return full names and
 * numeric ids; the public* methods return only public display names and
 * players.public_id (see PublicLeaderboardRow / PublicSessionResults).
 *
 * @phpstan-type PublicRow array{rank: int|null, id: string, name: string, played: int, wins: int, losses: int, win_pct: int, point_diff: int}
 * @phpstan-type PublicLeaderboard array{period: string, min_games: int, ranked: list<PublicRow>, unranked: list<PublicRow>}
 * @phpstan-type LogRow array{id: int, court: int|null, status: string, void: bool, finished_at: Carbon|null, duration_minutes: int|null, team_a: list<string>, team_b: list<string>, score_a: int|null, score_b: int|null}
 * @phpstan-type PublicLogRow array{court: int|null, finished_at: Carbon|null, duration_minutes: int|null, team_a: list<string>, team_b: list<string>, score_a: int|null, score_b: int|null}
 * @phpstan-type PublicSessionResults array{standings: list<PublicRow>, matches: list<PublicLogRow>}
 * @phpstan-type ProfileRecord array{played: int, wins: int, losses: int, win_pct: int|null, points_for: int, points_against: int, point_diff: int, sessions_attended: int, last_played: Carbon|null}
 * @phpstan-type PartnerRow array{player_id: int, name: string, games: int, wins: int, win_pct: int}
 * @phpstan-type OpponentRow array{player_id: int, name: string, games: int, wins: int, losses: int}
 * @phpstan-type Profile array{record: ProfileRecord, partners: list<PartnerRow>, opponents: list<OpponentRow>}
 * @phpstan-type HistoryRow array{match_id: int, date: Carbon, session_id: int, session_name: string, partner: string|null, opponents: list<string>, score_for: int, score_against: int, won: bool}
 */
class StatsService
{
    public const PER_PAGE = 20;

    public const PUBLIC_CACHE_SECONDS = 60;

    public function __construct(private readonly Ranker $ranker = new Ranker) {}

    // ---------------------------------------------------------------- standings

    /**
     * Standings of one session (staff view; a live session shows "so far").
     * Wins, win %, point diff, name; no minimum games. Shared ranks.
     *
     * @return list<RankedRow> Row ids are numeric players.id.
     */
    public function sessionStandings(PlaySession $session): array
    {
        return $this->ranker->session($this->aggregate(
            (int) $session->club_id,
            fn (Builder $q) => $q->where('m.play_session_id', $session->id),
        ));
    }

    // -------------------------------------------------------------- leaderboard

    /**
     * Club leaderboard for a period (staff view). Active players only, and only
     * players with at least one game in the period appear. Players under the
     * club's minimum games are in `unranked` (played desc, name).
     *
     * @return array{ranked: list<RankedRow>, unranked: list<RankedRow>, min_games: int}
     */
    public function leaderboard(Club $club, StatsPeriod $period = StatsPeriod::AllTime): array
    {
        $rows = $this->aggregate(
            (int) $club->id,
            fn (Builder $q) => $this->wherePeriod($q, $period),
            activeOnly: true,
        );
        $min = max(1, (int) $club->leaderboard_min_games);

        return $this->ranker->leaderboard($rows, $min) + ['min_games' => $min];
    }

    // ------------------------------------------------------------------- public

    /**
     * Resolver for /c/{club:slug}/stats: 404 unless the club has public stats on.
     */
    public function resolvePublicClub(Club $club): Club
    {
        if (! $club->public_stats) {
            throw new NotFoundHttpException;
        }

        return $club;
    }

    /**
     * Cached (60 s per club and period) public leaderboard. Contains only
     * public display names and players.public_id, never full names, DUPR IDs or
     * numeric ids.
     *
     * @return PublicLeaderboard
     */
    public function publicLeaderboard(Club $club, StatsPeriod $period = StatsPeriod::AllTime): array
    {
        $this->resolvePublicClub($club);

        /** @var PublicLeaderboard $data */
        $data = Cache::remember(
            "public-stats:{$club->id}:{$period->value}",
            self::PUBLIC_CACHE_SECONDS,
            function () use ($club, $period): array {
                $board = $this->leaderboard($club, $period);
                $map = $this->publicPlayers(array_map(
                    fn (RankedRow $r): int => (int) $r->row->id,
                    [...$board['ranked'], ...$board['unranked']],
                ));

                return [
                    'period' => $period->value,
                    'min_games' => $board['min_games'],
                    'ranked' => $this->publicRows($board['ranked'], $map),
                    'unranked' => $this->publicRows($board['unranked'], $map),
                ];
            },
        );

        return $data;
    }

    /**
     * Final standings and done-only match log of an ended session, for the
     * public page. Null unless the session belongs to the club, is ended, and
     * the club has public stats on. Public names and public ids only.
     *
     * @return PublicSessionResults|null
     */
    public function publicEndedSession(Club $club, PlaySession $session): ?array
    {
        if (! $club->public_stats || $session->club_id !== $club->id || $session->status !== SessionStatus::Ended) {
            return null;
        }

        /** @var PublicSessionResults $data */
        $data = Cache::remember("public-session-stats:{$session->id}", self::PUBLIC_CACHE_SECONDS, fn (): array => $this->buildPublicEndedSession($session));

        return $data;
    }

    /**
     * @return PublicSessionResults
     */
    private function buildPublicEndedSession(PlaySession $session): array
    {
        $standings = $this->sessionStandings($session);
        $matchPlayers = $this->matchPlayerNames($session, includeVoid: false);

        $log = [];
        foreach ($this->logRows($session, false, $matchPlayers) as $row) {
            $log[] = [
                'court' => $row['court'],
                'finished_at' => $row['finished_at'],
                'duration_minutes' => $row['duration_minutes'],
                'team_a' => $row['team_a'],
                'team_b' => $row['team_b'],
                'score_a' => $row['score_a'],
                'score_b' => $row['score_b'],
            ];
        }

        return [
            'standings' => $this->publicRows($standings, $this->publicPlayers(array_map(fn (RankedRow $r): int => (int) $r->row->id, $standings))),
            'matches' => $log,
        ];
    }

    // ---------------------------------------------------------------- match log

    /**
     * Match log of a session (staff): done matches in finish order, then (only
     * when $includeVoid) void matches flagged `void`. Full names, by slot.
     *
     * @return list<LogRow>
     */
    public function matchLog(PlaySession $session, bool $includeVoid = false): array
    {
        return $this->logRows($session, $includeVoid, $this->matchPlayerNames($session, $includeVoid));
    }

    // ----------------------------------------------------------------- profile

    /**
     * Record, partner and opponent tables for one player in a period. The
     * player must belong to the club (404 otherwise). Inactive players work.
     *
     * @return Profile
     */
    public function profile(Club $club, Player $player, StatsPeriod $period = StatsPeriod::AllTime): array
    {
        $this->assertInClub($club, $player);

        $rows = [];
        foreach ($this->playerMatches($club, $player, $period)->orderBy('s.date')->orderBy('m.id')->get() as $r) {
            $rows[] = $this->matchRow($r);
        }

        $wins = $pf = $pa = 0;
        $sessions = [];
        $last = null;
        $byMatch = [];
        foreach ($rows as $r) {
            [$for, $against] = $this->sides($r['team'], $r['a'], $r['b']);
            $pf += $for;
            $pa += $against;
            $wins += $for > $against ? 1 : 0;
            $sessions[$r['session_id']] = true;
            $last = $r['date'];
            $byMatch[$r['match_id']] = ['team' => $r['team'], 'won' => $for > $against];
        }
        $played = count($rows);

        $names = [];
        $partnerGames = $partnerWins = $oppGames = $oppWins = [];
        foreach ($this->othersIn(array_keys($byMatch), (int) $player->id) as $o) {
            $mine = $byMatch[$o['match_id']];
            $id = $o['player_id'];
            $names[$id] = $o['name'];
            $won = $mine['won'] ? 1 : 0;
            if ($o['team'] === $mine['team']) {
                $partnerGames[$id] = ($partnerGames[$id] ?? 0) + 1;
                $partnerWins[$id] = ($partnerWins[$id] ?? 0) + $won;
            } else {
                $oppGames[$id] = ($oppGames[$id] ?? 0) + 1;
                $oppWins[$id] = ($oppWins[$id] ?? 0) + $won;
            }
        }

        $partners = [];
        foreach ($partnerGames as $id => $games) {
            $w = $partnerWins[$id] ?? 0;
            $partners[] = ['player_id' => $id, 'name' => $names[$id], 'games' => $games, 'wins' => $w, 'win_pct' => (int) round($w * 100 / $games)];
        }
        $opponents = [];
        foreach ($oppGames as $id => $games) {
            $w = $oppWins[$id] ?? 0;
            $opponents[] = ['player_id' => $id, 'name' => $names[$id], 'games' => $games, 'wins' => $w, 'losses' => $games - $w];
        }
        $order = fn (array $a, array $b): int => ($b['games'] <=> $a['games']) ?: strcasecmp($a['name'], $b['name']) ?: $a['player_id'] <=> $b['player_id'];
        usort($partners, $order);
        usort($opponents, $order);

        return [
            'record' => [
                'played' => $played,
                'wins' => $wins,
                'losses' => $played - $wins,
                'win_pct' => $played === 0 ? null : (int) round($wins * 100 / $played),
                'points_for' => $pf,
                'points_against' => $pa,
                'point_diff' => $pf - $pa,
                'sessions_attended' => count($sessions),
                'last_played' => $last,
            ],
            'partners' => $partners,
            'opponents' => $opponents,
        ];
    }

    /**
     * Match history of a player, newest first, 20 per page. Scores are from the
     * player's side.
     *
     * @return LengthAwarePaginator<int, HistoryRow>
     */
    public function matchHistory(Club $club, Player $player, StatsPeriod $period = StatsPeriod::AllTime, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $this->assertInClub($club, $player);

        $page = $this->playerMatches($club, $player, $period)
            ->orderByDesc('s.date')
            ->orderByDesc('m.finished_at')
            ->orderByDesc('m.id')
            ->paginate($perPage);

        $ids = [];
        foreach ($page->items() as $r) {
            $ids[] = $this->matchRow($r)['match_id'];
        }
        $others = [];
        foreach ($this->othersIn($ids, (int) $player->id) as $o) {
            $others[$o['match_id']][] = $o;
        }

        return $page->through(function (object $r) use ($others): array {
            $row = $this->matchRow($r);
            $mid = $row['match_id'];
            $team = $row['team'];
            $partner = null;
            $opponents = [];
            foreach ($others[$mid] ?? [] as $o) {
                if ($o['team'] === $team) {
                    $partner = $o['name'];
                } else {
                    $opponents[] = $o['name'];
                }
            }
            [$for, $against] = $this->sides($team, $row['a'], $row['b']);

            return [
                'match_id' => $mid,
                'date' => $row['date'],
                'session_id' => $row['session_id'],
                'session_name' => $row['session_name'],
                'partner' => $partner,
                'opponents' => $opponents,
                'score_for' => $for,
                'score_against' => $against,
                'won' => $for > $against,
            ];
        });
    }

    // ------------------------------------------------------------ sessions list

    /**
     * Staff sessions list: live first, then drafts, then ended (newest date
     * first inside each), 20 per page. Every row has `done_matches_count`,
     * `players_count` (everyone checked in, including those who left) and
     * `checked_in_count` (not left).
     *
     * @return LengthAwarePaginator<int, PlaySession>
     */
    public function sessionsList(Club $club, ?SessionStatus $status = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $club->playSessions()
            ->withCount([
                'matches as done_matches_count' => fn ($q) => $q->where('status', MatchStatus::Done->value),
                'sessionPlayers as players_count',
                'sessionPlayers as checked_in_count' => fn ($q) => $q->where('status', '!=', SessionPlayerStatus::Left->value),
            ])
            ->when($status !== null, fn ($q) => $q->where('status', $status?->value))
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [SessionStatus::Live->value, SessionStatus::Draft->value])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Per-player totals over done, fully scored matches of a club, grouped in SQL.
     *
     * @param  callable(Builder): mixed  $scope
     * @return list<StatRow>
     */
    private function aggregate(int $clubId, callable $scope, bool $activeOnly = false): array
    {
        $won = "(mp.team = 'A' and m.team_a_score > m.team_b_score) or (mp.team = 'B' and m.team_b_score > m.team_a_score)";

        $query = DB::table('match_players as mp')
            ->join('matches as m', 'm.id', '=', 'mp.match_id')
            ->join('play_sessions as s', 's.id', '=', 'm.play_session_id')
            ->join('players as p', 'p.id', '=', 'mp.player_id')
            ->where('s.club_id', $clubId)
            ->where('p.club_id', $clubId)
            ->where('m.status', MatchStatus::Done->value)
            ->whereNotNull('m.team_a_score')
            ->whereNotNull('m.team_b_score')
            ->when($activeOnly, fn (Builder $q) => $q->where('p.active', true))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.id as player_id, p.name as player_name, count(*) as played')
            ->selectRaw("sum(case when {$won} then 1 else 0 end) as wins")
            ->selectRaw("sum(case when mp.team = 'A' then m.team_a_score else m.team_b_score end) as points_for")
            ->selectRaw("sum(case when mp.team = 'A' then m.team_b_score else m.team_a_score end) as points_against");

        $scope($query);

        $rows = [];
        foreach ($query->get() as $r) {
            $rows[] = new StatRow((int) $r->player_id, (string) $r->player_name, (int) $r->played, (int) $r->wins, (int) $r->points_for, (int) $r->points_against);
        }

        return $rows;
    }

    private function wherePeriod(Builder $query, StatsPeriod $period): Builder
    {
        $range = $period->range(now());
        if ($range !== null) {
            // whereDate: the date column may hold 'Y-m-d' or 'Y-m-d 00:00:00'.
            $query->whereDate('s.date', '>=', $range[0])->whereDate('s.date', '<=', $range[1]);
        }

        return $query;
    }

    /** One player's done, scored matches in the club and period (one row per match). */
    private function playerMatches(Club $club, Player $player, StatsPeriod $period): Builder
    {
        $query = DB::table('match_players as mp')
            ->join('matches as m', 'm.id', '=', 'mp.match_id')
            ->join('play_sessions as s', 's.id', '=', 'm.play_session_id')
            ->where('mp.player_id', $player->id)
            ->where('s.club_id', $club->id)
            ->where('m.status', MatchStatus::Done->value)
            ->whereNotNull('m.team_a_score')
            ->whereNotNull('m.team_b_score')
            ->select('m.id as match_id', 'm.team_a_score', 'm.team_b_score', 'mp.team', 's.id as play_session_id', 's.name as session_name', 's.date');

        return $this->wherePeriod($query, $period);
    }

    /**
     * The other players in the given matches.
     *
     * @param  list<int>  $matchIds
     * @return list<array{match_id: int, team: string, player_id: int, name: string}>
     */
    private function othersIn(array $matchIds, int $exceptPlayerId): array
    {
        $out = [];
        foreach (array_chunk($matchIds, 500) as $chunk) {
            $rows = DB::table('match_players as mp')
                ->join('players as p', 'p.id', '=', 'mp.player_id')
                ->whereIn('mp.match_id', $chunk)
                ->where('mp.player_id', '!=', $exceptPlayerId)
                ->orderBy('mp.match_id')->orderBy('mp.team')->orderBy('mp.slot')
                ->get(['mp.match_id', 'mp.team', 'p.id as player_id', 'p.name']);
            foreach ($rows as $r) {
                $out[] = ['match_id' => (int) $r->match_id, 'team' => (string) $r->team, 'player_id' => (int) $r->player_id, 'name' => (string) $r->name];
            }
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int} points for and against from a team's side
     */
    private function sides(string $team, int $a, int $b): array
    {
        return $team === 'A' ? [$a, $b] : [$b, $a];
    }

    /**
     * @return array{match_id: int, team: string, a: int, b: int, session_id: int, session_name: string, date: Carbon}
     */
    private function matchRow(object $r): array
    {
        $f = get_object_vars($r);

        return [
            'match_id' => (int) $f['match_id'],
            'team' => (string) $f['team'],
            'a' => (int) $f['team_a_score'],
            'b' => (int) $f['team_b_score'],
            'session_id' => (int) $f['play_session_id'],
            'session_name' => (string) $f['session_name'],
            'date' => Carbon::parse((string) $f['date'])->startOfDay(),
        ];
    }

    private function assertInClub(Club $club, Player $player): void
    {
        if ($player->club_id !== $club->id) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Team members per match, by slot, with names as entered.
     *
     * @return array<int, array{A: list<string>, B: list<string>}>
     */
    private function matchPlayerNames(PlaySession $session, bool $includeVoid): array
    {
        $statuses = $includeVoid ? [MatchStatus::Done->value, MatchStatus::Void->value] : [MatchStatus::Done->value];

        $rows = DB::table('match_players as mp')
            ->join('matches as m', 'm.id', '=', 'mp.match_id')
            ->join('players as p', 'p.id', '=', 'mp.player_id')
            ->where('m.play_session_id', $session->id)
            ->where('p.club_id', $session->club_id)
            ->whereIn('m.status', $statuses)
            ->orderBy('mp.match_id')->orderBy('mp.team')->orderBy('mp.slot')
            ->get(['mp.match_id', 'mp.team', 'p.name']);

        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r->name;
            $out[(int) $r->match_id] ??= ['A' => [], 'B' => []];
            $out[(int) $r->match_id][(string) $r->team === 'B' ? 'B' : 'A'][] = $name;
        }

        return $out;
    }

    /**
     * @param  array<int, array{A?: list<string>, B?: list<string>}>  $names
     * @return list<LogRow>
     */
    private function logRows(PlaySession $session, bool $includeVoid, array $names): array
    {
        $statuses = $includeVoid ? [MatchStatus::Done->value, MatchStatus::Void->value] : [MatchStatus::Done->value];

        $matches = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->whereIn('status', $statuses)
            ->orderByRaw('case status when ? then 0 else 1 end', [MatchStatus::Done->value])
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($matches as $m) {
            $duration = $m->started_at !== null && $m->finished_at !== null
                ? (int) round(abs($m->finished_at->diffInSeconds($m->started_at)) / 60)
                : null;
            $out[] = [
                'id' => (int) $m->id,
                'court' => $m->court_no,
                'status' => $m->status->value,
                'void' => $m->status === MatchStatus::Void,
                'finished_at' => $m->finished_at,
                'duration_minutes' => $duration,
                'team_a' => $names[$m->id]['A'] ?? [],
                'team_b' => $names[$m->id]['B'] ?? [],
                'score_a' => $m->team_a_score,
                'score_b' => $m->team_b_score,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{public_id: string, name: string}>
     */
    private function publicPlayers(array $ids): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            foreach (Player::query()->whereIn('id', $chunk)->get(['id', 'public_id', 'name']) as $p) {
                $map[(int) $p->id] = ['public_id' => (string) $p->public_id, 'name' => $p->name];
            }
        }

        return $map;
    }

    /**
     * @param  list<RankedRow>  $rows
     * @param  array<int, array{public_id: string, name: string}>  $players
     * @return list<PublicRow>
     */
    private function publicRows(array $rows, array $players): array
    {
        $out = [];
        foreach ($rows as $r) {
            $p = $players[(int) $r->row->id] ?? ['public_id' => '', 'name' => ''];
            $out[] = [
                'rank' => $r->rank,
                'id' => $p['public_id'],
                'name' => $p['name'],
                'played' => $r->row->played,
                'wins' => $r->row->wins,
                'losses' => $r->row->losses(),
                'win_pct' => $r->row->winPercent(),
                'point_diff' => $r->row->pointDiff(),
            ];
        }

        return $out;
    }
}
