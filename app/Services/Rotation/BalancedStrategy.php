<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\ReplacementResult;
use App\Domain\Rotation\RotationEngine;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;

/**
 * The v1 balanced mode: one shared queue, Up Next slots filled by the engine.
 * This is the code that used to live in MatchService, moved unchanged.
 */
class BalancedStrategy implements RotationStrategy
{
    public function __construct(protected readonly RotationEngine $engine) {}

    public function fillUpNext(PlaySession $session, RotationQueries $queries): void
    {
        $staged = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->orderByDesc('id')
            ->get();
        foreach ($staged->take(max(0, $staged->count() - $session->up_next_count)) as $surplus) {
            $surplus->status = MatchStatus::Void;
            $surplus->save();
        }

        while ($queries->stagedCount() < $session->up_next_count && $this->stage($session, $queries) !== null) {
            // keep staging
        }
    }

    public function reroll(PlaySession $session, RotationQueries $queries, array $previousPlayerIds, GameMatch $voided): void
    {
        // Run the engine once with each old player left out and keep the cheapest result,
        // so the exact same four can never come back while a different group exists.
        // Only when every run finds nothing (fewer than 5 waiting) may the refill re-stage them.
        $candidates = $this->waiting($session, $queries);
        $history = $queries->history();
        $best = null;
        foreach ($previousPlayerIds as $leaveOut) {
            $result = $this->engine->pickMatch(
                array_values(array_filter($candidates, fn (Candidate $c): bool => $c->id !== $leaveOut)),
                $history,
            );
            if ($result !== null && ($best === null || $result->cost < $best->cost)) {
                $best = $result;
            }
        }
        if ($best !== null) {
            $queries->createStaged($best);
        }
    }

    public function pickReplacement(PlaySession $session, RotationQueries $queries, array $teamA, array $teamB, int $exceptMatchId): ?ReplacementResult
    {
        return $this->engine->pickReplacement(
            $teamA,
            $teamB,
            $this->waiting($session, $queries),
            $queries->history($exceptMatchId),
        );
    }

    public function afterFinish(PlaySession $session, RotationQueries $queries, GameMatch $match): void
    {
        // Balanced mode routes nobody: finished players simply rejoin the queue.
    }

    public function allowedCourts(PlaySession $session, GameMatch $match): array
    {
        return $session->courts >= 1 ? range(1, $session->courts) : [];
    }

    public function reservedPlayerIds(PlaySession $session, RotationQueries $queries): array
    {
        return [];
    }

    /**
     * Waiting players this mode may stage, minus the reserved ones.
     *
     * @return list<Candidate>
     */
    protected function waiting(PlaySession $session, RotationQueries $queries): array
    {
        return $queries->candidates($this->reservedPlayerIds($session, $queries));
    }

    public function guardSwap(PlaySession $session, GameMatch $match, Player $out, Player $in): void
    {
        // Balanced mode accepts any available player.
    }

    protected function stage(PlaySession $session, RotationQueries $queries): ?GameMatch
    {
        $result = $this->engine->pickMatch(
            $this->waiting($session, $queries),
            $queries->history(),
        );

        return $result === null ? null : $queries->createStaged($result);
    }
}
