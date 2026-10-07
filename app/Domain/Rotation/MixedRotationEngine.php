<?php

namespace App\Domain\Rotation;

use App\Enums\Gender;
use InvalidArgumentException;

/**
 * Mixed doubles rotation (PLAN.md Phase 7 rules). Pure PHP, deterministic.
 *
 * Every team is one man and one woman. The top `window` men and the top
 * `window` women (by the shared priority order) are searched: every pair of
 * men with every pair of women, in both man/woman pairings. Skipped priority
 * is measured against the top 2 men plus the top 2 women, so leaving someone
 * out because of their gender costs nothing. Fewer than 2 men or 2 women
 * means no match (the slot waits, there is no fallback). Players with no
 * gender are never candidates.
 */
final class MixedRotationEngine implements RotationEngine
{
    private readonly MatchCost $matchCost;

    public function __construct(private readonly Weights $weights)
    {
        $this->matchCost = new MatchCost($weights);
    }

    public function pickMatch(array $candidates, PairHistory $history): ?MatchResult
    {
        $sorted = Priority::sort($candidates);
        $men = [];
        $women = [];
        foreach ($sorted as $rank => $candidate) {
            if ($candidate->gender === Gender::Man) {
                $men[] = [$candidate, $rank];
            } elseif ($candidate->gender === Gender::Woman) {
                $women[] = [$candidate, $rank];
            }
        }
        if (count($men) < 2 || count($women) < 2) {
            return null;
        }

        $men = array_slice($men, 0, $this->weights->window);
        $women = array_slice($women, 0, $this->weights->window);

        $best = null;
        $bestSkipped = 0;
        $bestIds = [];

        for ($i = 0; $i < count($men) - 1; $i++) {
            for ($j = $i + 1; $j < count($men); $j++) {
                for ($k = 0; $k < count($women) - 1; $k++) {
                    for ($l = $k + 1; $l < count($women); $l++) {
                        $skipped = $i + $j + $k + $l - 2;
                        [$m1, $m2] = [$men[$i][0], $men[$j][0]];
                        [$w1, $w2] = [$women[$k][0], $women[$l][0]];
                        $ids = [$m1->id, $m2->id, $w1->id, $w2->id];
                        sort($ids);

                        foreach ([[[$m1, $w1], [$m2, $w2]], [[$m1, $w2], [$m2, $w1]]] as [$teamA, $teamB]) {
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

        $remaining = $openOnA ? $teamA[0] : $teamB[0];
        $needed = match ($remaining->gender) {
            Gender::Man => Gender::Woman,
            Gender::Woman => Gender::Man,
            default => null,
        };
        if ($needed === null) {
            return null;
        }

        $eligible = array_values(array_filter($candidates, static fn (Candidate $c): bool => $c->gender === $needed));
        $ranked = array_slice(Priority::sort($eligible), 0, $this->weights->window);
        $best = null;

        foreach ($ranked as $rank => $candidate) {
            $fullA = $openOnA ? [...$teamA, $candidate] : array_values($teamA);
            $fullB = $openOnA ? array_values($teamB) : [...$teamB, $candidate];
            $breakdown = $this->matchCost->breakdown($fullA, $fullB, $rank, $history);
            $cost = array_sum($breakdown);

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
