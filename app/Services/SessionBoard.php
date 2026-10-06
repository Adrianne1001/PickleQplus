<?php

namespace App\Services;

use App\Domain\Rotation\WaitEstimator;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only view data for the organizer board. Changes no state: it returns
 * plain arrays that the board components render.
 *
 * @phpstan-type PlayerRow array{id: int, name: string, stars: int|null}
 * @phpstan-type MatchRow array{id: int, court: int|null, status: string, teams: array{A: list<PlayerRow>, B: list<PlayerRow>}, player_ids: list<int>, elapsed_minutes: int|null, score_a: int|null, score_b: int|null, finished_at: CarbonInterface|null, exported: bool}
 * @phpstan-type CourtRow array{court: int, match: MatchRow|null}
 * @phpstan-type QueueRow array{id: int, name: string, stars: int|null, games_played: int, waited_minutes: int, estimate_minutes: int|null}
 */
class SessionBoard
{
    public const RECENT_LIMIT = 5;

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
     * @return list<QueueRow>
     */
    public function waiting(PlaySession $session): array
    {
        $open = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->whereIn('status', [MatchStatus::Staged->value, MatchStatus::Playing->value])
            ->with('matchPlayers')
            ->get();

        $inMatch = [];
        $elapsed = [];
        $stagedCount = 0;
        foreach ($open as $match) {
            foreach ($match->matchPlayers as $row) {
                $inMatch[] = $row->player_id;
            }
            if ($match->status === MatchStatus::Staged) {
                $stagedCount++;
            } else {
                $elapsed[] = $this->minutesSince($match->started_at);
            }
        }

        $entries = $this->entries($session, SessionPlayerStatus::Waiting)
            ->reject(fn (SessionPlayer $e): bool => in_array($e->player_id, $inMatch, true))
            ->values();

        $average = $this->averageMatchMinutes($session);

        $rows = [];
        foreach ($entries as $position => $entry) {
            $rows[] = $this->queueRow($entry, $this->estimator->estimate(
                $position,
                $session->courts,
                $stagedCount,
                $elapsed,
                $average,
            ));
        }

        return $rows;
    }

    /**
     * Players on break, longest on break first.
     *
     * @return list<QueueRow>
     */
    public function onBreak(PlaySession $session): array
    {
        return array_values($this->entries($session, SessionPlayerStatus::Break)
            ->map(fn (SessionPlayer $e): array => $this->queueRow($e, null))
            ->all());
    }

    /**
     * Last finished matches, newest first.
     *
     * @return list<MatchRow>
     */
    public function recent(PlaySession $session, int $limit = self::RECENT_LIMIT): array
    {
        return array_values(GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->with('matchPlayers.player')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (GameMatch $m): array => $this->matchRow($m))
            ->all());
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
     * @return QueueRow
     */
    private function queueRow(SessionPlayer $entry, ?int $estimate): array
    {
        return [
            'id' => $entry->player_id,
            'name' => $entry->player === null ? '' : $entry->player->name,
            'stars' => $entry->player === null ? null : $entry->player->stars,
            'games_played' => $entry->games_played,
            'waited_minutes' => $this->minutesSince($entry->queued_at ?? $entry->checked_in_at),
            'estimate_minutes' => $estimate,
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
            ];
            $ids[] = $row->player_id;
        }

        return [
            'id' => $match->id,
            'court' => $match->court_no,
            'status' => $match->status->value,
            'teams' => $teams,
            'player_ids' => $ids,
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
