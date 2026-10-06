<?php

namespace App\Domain\Rotation;

use InvalidArgumentException;

/**
 * Balanced rotation (PLAN.md section 3). Pure PHP, fully deterministic.
 *
 * Candidates are sorted by effective_games, queued_at, id; the top `window`
 * are searched across every 4-group and its 3 team splits. The lowest cost
 * wins; ties go to the lowest skipped-priority, then the lexicographically
 * smallest sorted id list, then the first split in enumeration order.
 */
final class BalancedRotationEngine implements RotationEngine
{
    private const EPSILON = 1e-9;

    public function __construct(private readonly Weights $weights) {}

    public function pickMatch(array $candidates, PairHistory $history): ?MatchResult
    {
        $window = array_slice($this->sortByPriority($candidates), 0, $this->weights->window);
        $n = count($window);
        if ($n < 4) {
            return null;
        }

        $best = null;
        $bestSkipped = 0;
        $bestIds = [];

        for ($i = 0; $i < $n - 3; $i++) {
            for ($j = $i + 1; $j < $n - 2; $j++) {
                for ($k = $j + 1; $k < $n - 1; $k++) {
                    for ($l = $k + 1; $l < $n; $l++) {
                        $skipped = $i + $j + $k + $l - 6;
                        $group = [$window[$i], $window[$j], $window[$k], $window[$l]];
                        usort($group, static fn (Candidate $a, Candidate $b): int => $a->id <=> $b->id);
                        $ids = array_map(static fn (Candidate $c): int => $c->id, $group);
                        [$a, $b, $c, $d] = $group;

                        foreach ([[[$a, $b], [$c, $d]], [[$a, $c], [$b, $d]], [[$a, $d], [$b, $c]]] as [$teamA, $teamB]) {
                            $breakdown = $this->breakdown($teamA, $teamB, $skipped, $history);
                            $cost = array_sum($breakdown);

                            if ($best === null || $this->isBetter($cost, $skipped, $ids, $best->cost, $bestSkipped, $bestIds)) {
                                $best = new MatchResult(
                                    [$teamA[0]->id, $teamA[1]->id],
                                    [$teamB[0]->id, $teamB[1]->id],
                                    $cost,
                                    $breakdown,
                                );
                                $bestSkipped = $skipped;
                                $bestIds = $ids;
                            }
                        }
                    }
                }
            }
        }

        return $best;
    }

    public function pickReplacement(array $teamA, array $teamB, array $candidates, PairHistory $history): ?ReplacementResult
    {
        $openOnA = count($teamA) === 1 && count($teamB) === 2;
        if (! $openOnA && ! (count($teamA) === 2 && count($teamB) === 1)) {
            throw new InvalidArgumentException('Exactly one team must have one player and the other two.');
        }

        $ranked = array_slice($this->sortByPriority($candidates), 0, $this->weights->window);
        $best = null;

        foreach ($ranked as $rank => $candidate) {
            $fullA = $openOnA ? [...$teamA, $candidate] : array_values($teamA);
            $fullB = $openOnA ? array_values($teamB) : [...$teamB, $candidate];
            $breakdown = $this->breakdown($fullA, $fullB, $rank, $history);
            $cost = array_sum($breakdown);

            // Strictly better only: on equal cost the earlier (higher priority) candidate stays.
            if ($best === null || $cost < $best->cost - self::EPSILON) {
                $best = new ReplacementResult(
                    $candidate->id,
                    array_map(static fn (Candidate $c): int => $c->id, $fullA),
                    array_map(static fn (Candidate $c): int => $c->id, $fullB),
                    $cost,
                    $breakdown,
                );
            }
        }

        return $best;
    }

    /**
     * @param  array<Candidate>  $candidates
     * @return list<Candidate>
     */
    private function sortByPriority(array $candidates): array
    {
        $sorted = array_values($candidates);
        usort($sorted, static fn (Candidate $a, Candidate $b): int => [$a->effectiveGames, $a->queuedAt, $a->id] <=> [$b->effectiveGames, $b->queuedAt, $b->id]);

        return $sorted;
    }

    /**
     * @param  array<Candidate>  $teamA
     * @param  array<Candidate>  $teamB
     * @return array{star_balance: float, repeat_partner: float, repeat_opponent: float, skipped_priority: float}
     */
    private function breakdown(array $teamA, array $teamB, int $skipped, PairHistory $history): array
    {
        $starsA = array_sum(array_map(static fn (Candidate $c): int => $c->stars, $teamA));
        $starsB = array_sum(array_map(static fn (Candidate $c): int => $c->stars, $teamB));

        $partners = 0;
        foreach ([$teamA, $teamB] as $team) {
            if (count($team) === 2) {
                $partners += $history->partnerCount($team[0]->id, $team[1]->id);
            }
        }

        $opponents = 0;
        foreach ($teamA as $a) {
            foreach ($teamB as $b) {
                $opponents += $history->opponentCount($a->id, $b->id);
            }
        }

        return [
            'star_balance' => abs($starsA - $starsB) * $this->weights->starBalance,
            'repeat_partner' => $partners * $this->weights->repeatPartner,
            'repeat_opponent' => $opponents * $this->weights->repeatOpponent,
            'skipped_priority' => $skipped * $this->weights->skippedPriority,
        ];
    }

    /**
     * @param  array<int>  $ids
     * @param  array<int>  $bestIds
     */
    private function isBetter(float $cost, int $skipped, array $ids, float $bestCost, int $bestSkipped, array $bestIds): bool
    {
        if (abs($cost - $bestCost) > self::EPSILON) {
            return $cost < $bestCost;
        }
        if ($skipped !== $bestSkipped) {
            return $skipped < $bestSkipped;
        }

        return $ids < $bestIds;
    }
}
