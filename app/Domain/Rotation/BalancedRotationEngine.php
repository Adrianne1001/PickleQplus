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
    private readonly MatchCost $matchCost;

    public function __construct(private readonly Weights $weights)
    {
        $this->matchCost = new MatchCost($weights);
    }

    public function pickMatch(array $candidates, PairHistory $history): ?MatchResult
    {
        $window = array_slice(Priority::sort($candidates), 0, $this->weights->window);
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
                            $breakdown = $this->matchCost->breakdown($teamA, $teamB, $skipped, $history);
                            $cost = array_sum($breakdown);

                            if ($best === null || $this->matchCost->isBetter($cost, $skipped, $ids, $best->cost, $bestSkipped, $bestIds)) {
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

        $ranked = array_slice(Priority::sort($candidates), 0, $this->weights->window);
        $best = null;

        foreach ($ranked as $rank => $candidate) {
            $fullA = $openOnA ? [...$teamA, $candidate] : array_values($teamA);
            $fullB = $openOnA ? array_values($teamB) : [...$teamB, $candidate];
            $breakdown = $this->matchCost->breakdown($fullA, $fullB, $rank, $history);
            $cost = array_sum($breakdown);

            // Strictly better only: on equal cost the earlier (higher priority) candidate stays.
            if ($best === null || $cost < $best->cost - MatchCost::EPSILON) {
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
}
