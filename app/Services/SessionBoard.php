<?php

namespace App\Services;

use App\Domain\Rotation\WaitEstimator;
use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\Rotation\SkillCourtsStrategy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view data for the organizer board. Changes no state: it returns
 * plain arrays that the board components render.
 *
 * @phpstan-type PlayerRow array{id: int, name: string, stars: int|null, gender: string|null}
 * @phpstan-type MatchRow array{id: int, court: int|null, status: string, teams: array{A: list<PlayerRow>, B: list<PlayerRow>}, player_ids: list<int>, group: int|null, elapsed_minutes: int|null, score_a: int|null, score_b: int|null, finished_at: CarbonInterface|null, exported: bool}
 * @phpstan-type RecentRow array{id: int, court: int|null, status: string, teams: array{A: list<PlayerRow>, B: list<PlayerRow>}, player_ids: list<int>, group: int|null, elapsed_minutes: int|null, score_a: int|null, score_b: int|null, finished_at: CarbonInterface|null, exported: bool, number: int, winner: 'A'|'B'|null, duration_minutes: int|null}
 * @phpstan-type CourtRow array{court: int, match: MatchRow|null}
 * @phpstan-type QueueRow array{id: int, name: string, stars: int|null, games_played: int, wins: int, waited_minutes: int, estimate_minutes: int|null, gender: string|null, needs_gender: bool, group: int|null, group_position: int|null}
 * @phpstan-type GroupRow array{index: int, label: string, min_stars: int, max_stars: int, courts: list<int>, waiting: int, staged: int}
 */
class SessionBoard
{
    public const RECENT_LIMIT = 20;

    public const AVERAGE_WINDOW = 10;

    public function __construct(private readonly WaitEstimator $estimator) {}

    /**
     * Every court 1..courts with its playing match, if any.
     *
     * @return list<CourtRow>
     */
    public function courts(PlaySession $session): array
    {
        $playing = [];
        foreach ($this->matches($session, MatchStatus::Playing) as $match) {
            if ($match->court_no !== null) {
                $playing[$match->court_no] = $this->matchRow($match);
            }
        }

        $courts = [];
        for ($court = 1; $court <= $session->courts; $court++) {
            $courts[] = ['court' => $court, 'match' => $playing[$court] ?? null];
        }

        return $courts;
    }

    /**
     * Court numbers with no playing match.
     *
     * @return list<int>
     */
    public function freeCourts(PlaySession $session): array
    {
        $busy = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Playing->value)
            ->pluck('court_no')
            ->map(fn ($c): int => (int) $c)
            ->all();

        $free = [];
        for ($court = 1; $court <= $session->courts; $court++) {
            if (! in_array($court, $busy, true)) {
                $free[] = $court;
            }
        }

        return $free;
    }

    /**
     * Staged (Up Next) matches, oldest first.
     *
     * @return list<MatchRow>
     */
    public function staged(PlaySession $session): array
    {
        return array_map($this->matchRow(...), $this->matches($session, MatchStatus::Staged));
    }

    /**
     * Waiting players who are not in a staged or playing match, in engine
     * priority order, each with a wait estimate. Position 0 is first in line.
     *
     * @param  array<int, int>  $wins  from winsByPlayer(); when omitted every row has wins 0 and no query runs
     * @return list<QueueRow>
     */
    public function waiting(PlaySession $session, array $wins = []): array
    {
        $open = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->whereIn('status', [MatchStatus::Staged->value, MatchStatus::Playing->value])
            ->with('matchPlayers')
            ->get();

        $inMatch = [];
        $elapsed = [];
        $stagedCount = 0;
        $stagedByGroup = [];
        $elapsedByCourt = [];
        foreach ($open as $match) {
            foreach ($match->matchPlayers as $row) {
                $inMatch[] = $row->player_id;
            }
            if ($match->status === MatchStatus::Staged) {
                $stagedCount++;
                if ($match->court_group !== null) {
                    $stagedByGroup[$match->court_group] = ($stagedByGroup[$match->court_group] ?? 0) + 1;
                }
            } else {
                $elapsed[] = $this->minutesSince($match->started_at);
                if ($match->court_no !== null) {
                    $elapsedByCourt[$match->court_no] = $this->minutesSince($match->started_at);
                }
            }
        }

        $entries = $this->entries($session, SessionPlayerStatus::Waiting)
            ->reject(fn (SessionPlayer $e): bool => in_array($e->player_id, $inMatch, true))
            ->values();

        $average = $this->averageMatchMinutes($session);

        $mixed = $session->rotation_mode === RotationMode::Mixed;
        $genderRank = [];
        $totals = [];
        if ($mixed) {
            foreach ($entries as $entry) {
                $g = $entry->player?->gender;
                if ($g !== null) {
                    $totals[$g->value] = ($totals[$g->value] ?? 0) + 1;
                }
            }
        }
        $skill = SkillCourtsStrategy::groupsFor($session);
        $groupPosition = [];
        $rows = [];
        foreach ($entries as $position => $entry) {
            if ($skill !== null) {
                // Each group is its own queue with its own courts and Up Next slots.
                $stars = $entry->player?->stars;
                $group = $stars === null ? null : $skill->tryGroupForStars($stars);
                if ($group === null) {
                    $rows[] = $this->queueRow($entry, null, $wins);

                    continue;
                }
                $inGroup = $groupPosition[$group] ?? 0;
                $groupPosition[$group] = $inGroup + 1;
                $courts = $skill->courtRange($group);
                $groupElapsed = array_values(array_intersect_key($elapsedByCourt, array_flip($courts)));
                $estimate = $this->estimator->estimate($inGroup, count($courts), $stagedByGroup[$group] ?? 0, $groupElapsed, $average);
                $rows[] = $this->queueRow($entry, $estimate, $wins, false, $group, $inGroup + 1);

                continue;
            }
            if ($mixed) {
                // Each team is 1 man + 1 woman, so a player's match is set by their rank within their own gender.
                $gender = $entry->player?->gender;
                if ($gender === null) {
                    $estimate = null;
                } else {
                    $rank = $genderRank[$gender->value] ?? 0;
                    $genderRank[$gender->value] = $rank + 1;
                    // The player's match needs 2 of their own gender and 2 of the other, so with too few
                    // waiting (no partner, or no one of the other gender) no start time can be promised.
                    $needed = 2 * intdiv($rank, 2) + 2;
                    $other = $gender === Gender::Man ? Gender::Woman : Gender::Man;
                    $estimate = ($totals[$gender->value] ?? 0) < $needed || ($totals[$other->value] ?? 0) < $needed
                        ? null
                        : $this->estimator->estimateFromMatchIndex($stagedCount + intdiv($rank, 2), $session->courts, $elapsed, $average);
                }
            } else {
                $estimate = $this->estimator->estimate($position, $session->courts, $stagedCount, $elapsed, $average);
            }
            $rows[] = $this->queueRow($entry, $estimate, $wins, $mixed);
        }

        return $rows;
    }

    /**
     * The skill groups of a skill courts session with their star ranges, court numbers,
     * waiting counts (from waiting() rows, pass them in to avoid a second query) and
     * staged counts. Empty in every other mode. Staff-only: the stars are not public.
     *
     * @param  list<QueueRow>|null  $waitingRows
     * @return list<GroupRow>
     */
    public function groups(PlaySession $session, ?array $waitingRows = null): array
    {
        $skill = SkillCourtsStrategy::groupsFor($session);
        if ($skill === null) {
            return [];
        }

        $waitingRows ??= $this->waiting($session);
        $staged = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->whereNotNull('court_group')
            ->selectRaw('court_group, count(*) as total')
            ->groupBy('court_group')
            ->pluck('total', 'court_group');

        $rows = [];
        for ($group = 1; $group <= $skill->count(); $group++) {
            $range = $skill->starRange($group);
            $rows[] = [
                'index' => $group,
                'label' => $skill->label($group),
                'min_stars' => $range['min'],
                'max_stars' => $range['max'],
                'courts' => $skill->courtRange($group),
                'waiting' => count(array_filter($waitingRows, static fn (array $row): bool => $row['group'] === $group)),
                'staged' => (int) ($staged[$group] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The session's rotation mode value (balanced, mixed, ...).
     */
    public function mode(PlaySession $session): string
    {
        return $session->rotation_mode->value;
    }

    /**
     * Waiting players the mode cannot place at all: in mixed doubles, players
     * with no gender. Always 0 in other modes. Staff-only.
     */
    public function unplaceableCount(PlaySession $session): int
    {
        if ($session->rotation_mode !== RotationMode::Mixed) {
            return 0;
        }

        return $this->unplaceableIn($this->waiting($session));
    }

    /**
     * The same count from waiting rows that were already fetched (no second query).
     *
     * @param  list<QueueRow>  $waitingRows  rows from waiting()
     */
    public function unplaceableIn(array $waitingRows): int
    {
        return count(array_filter($waitingRows, static fn (array $row): bool => $row['needs_gender']));
    }

    /**
     * Players on break, longest on break first.
     *
     * @param  array<int, int>  $wins  from winsByPlayer(); when omitted every row has wins 0 and no query runs
     * @return list<QueueRow>
     */
    public function onBreak(PlaySession $session, array $wins = []): array
    {

        return array_values($this->entries($session, SessionPlayerStatus::Break)
            ->map(fn (SessionPlayer $e): array => $this->queueRow($e, null, $wins))
            ->all());
    }

    /**
     * Last finished matches, newest first. Each row carries its 1-based position among the
     * session's done matches in finish order (oldest = 1), the winning team and the duration.
     *
     * @return list<RecentRow>
     */
    public function recent(PlaySession $session, int $limit = self::RECENT_LIMIT): array
    {
        $matches = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->with('matchPlayers.player')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->values();

        $total = $this->doneCount($session);
        $rows = [];
        foreach ($matches as $index => $match) {
            $winner = null;
            if ($match->team_a_score !== null && $match->team_b_score !== null && $match->team_a_score !== $match->team_b_score) {
                $winner = $match->team_a_score > $match->team_b_score ? 'A' : 'B';
            }
            $duration = $match->started_at === null || $match->finished_at === null
                ? null
                : max(0, (int) floor($match->started_at->diffInSeconds($match->finished_at, true) / 60));

            $rows[] = $this->matchRow($match) + [
                'number' => $total - $index,
                'winner' => $winner,
                'duration_minutes' => $duration,
            ];
        }

        return $rows;
    }

    /**
     * Number of done matches in the session.
     */
    public function doneCount(PlaySession $session): int
    {
        return GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->count();
    }

    /**
     * Id of the session's most recent done match (the one undo applies to).
     */
    public function lastDoneId(PlaySession $session): ?int
    {
        $id = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Average length of the last 10 done matches, or the configured default.
     */
    public function averageMatchMinutes(PlaySession $session): float
    {
        $durations = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(self::AVERAGE_WINDOW)
            ->get(['started_at', 'finished_at'])
            ->map(fn (GameMatch $m): float => max(0.0, (float) $m->started_at?->diffInSeconds($m->finished_at, true)) / 60)
            ->all();

        if ($durations === []) {
            return (float) config('pickleq.rotation.avg_match_minutes', 15);
        }

        return array_sum($durations) / count($durations);
    }

    /**
     * Wins in this session per player id (one grouped query). A win is a done match with both
     * scores set where the player's team scored higher. Players with no wins are absent.
     *
     * @return array<int, int>
     */
    public function winsByPlayer(PlaySession $session): array
    {
        $rows = DB::table('match_players as mp')
            ->join('matches as m', 'm.id', '=', 'mp.match_id')
            ->where('m.play_session_id', $session->id)
            ->where('m.status', MatchStatus::Done->value)
            ->whereNotNull('m.team_a_score')
            ->whereNotNull('m.team_b_score')
            ->groupBy('mp.player_id')
            ->selectRaw('mp.player_id as player_id')
            ->selectRaw('sum(case when '.StatsService::WON_SQL.' then 1 else 0 end) as wins')
            ->get();

        $wins = [];
        foreach ($rows as $row) {
            if ((int) $row->wins > 0) {
                $wins[(int) $row->player_id] = (int) $row->wins;
            }
        }

        return $wins;
    }

    /**
     * @return list<GameMatch>
     */
    private function matches(PlaySession $session, MatchStatus $status): array
    {
        return array_values(GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', $status->value)
            ->with('matchPlayers.player')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Engine priority: fewest effective games, then longest wait, then id.
     *
     * @return Collection<int, SessionPlayer>
     */
    private function entries(PlaySession $session, SessionPlayerStatus $status): Collection
    {
        return SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('status', $status->value)
            ->with('player')
            ->orderByRaw('(games_played + games_credit)')
            ->orderBy('queued_at')
            ->orderBy('player_id')
            ->get();
    }

    /**
     * @param  array<int, int>  $wins
     * @return QueueRow
     */
    private function queueRow(SessionPlayer $entry, ?int $estimate, array $wins, bool $mixed = false, ?int $group = null, ?int $groupPosition = null): array
    {
        return [
            'id' => $entry->player_id,
            'name' => $entry->player === null ? '' : $entry->player->name,
            'stars' => $entry->player === null ? null : $entry->player->stars,
            'games_played' => $entry->games_played,
            'wins' => $wins[$entry->player_id] ?? 0,
            'waited_minutes' => $this->minutesSince($entry->queued_at ?? $entry->checked_in_at),
            'estimate_minutes' => $estimate,
            'gender' => $entry->player?->gender?->value,
            'needs_gender' => $mixed && $entry->player?->gender === null,
            'group' => $group,
            'group_position' => $groupPosition,
        ];
    }

    /**
     * @return MatchRow
     */
    private function matchRow(GameMatch $match): array
    {
        $teams = ['A' => [], 'B' => []];
        $ids = [];
        $rows = $match->matchPlayers->sortBy([['team', 'asc'], ['slot', 'asc']]);

        foreach ($rows as $row) {
            $teams[$row->team === Team::A ? 'A' : 'B'][] = [
                'id' => $row->player_id,
                'name' => $row->player === null ? '' : $row->player->name,
                'stars' => $row->player === null ? null : $row->player->stars,
                'gender' => $row->player?->gender?->value,
            ];
            $ids[] = $row->player_id;
        }

        return [
            'id' => $match->id,
            'court' => $match->court_no,
            'status' => $match->status->value,
            'teams' => $teams,
            'player_ids' => $ids,
            'group' => $match->court_group,
            'elapsed_minutes' => $match->status === MatchStatus::Playing ? $this->minutesSince($match->started_at) : null,
            'score_a' => $match->team_a_score,
            'score_b' => $match->team_b_score,
            'finished_at' => $match->finished_at,
            'exported' => $match->dupr_exported_at !== null,
        ];
    }

    private function minutesSince(?CarbonInterface $time): int
    {
        return $time === null ? 0 : max(0, (int) floor($time->diffInMinutes(Carbon::now(), true)));
    }
}
