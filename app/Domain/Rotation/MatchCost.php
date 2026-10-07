<?php

namespace App\Domain\Rotation;

/**
 * The weighted cost of a proposed match and the tie-break between two of them
 * (PLAN.md section 3). Shared by the balanced and mixed engines.
 */
final class MatchCost
{
    public const EPSILON = 1e-9;

    public function __construct(private readonly Weights $weights) {}

    /**
     * @param  array<Candidate>  $teamA
     * @param  array<Candidate>  $teamB
     * @return array{star_balance: float, repeat_partner: float, repeat_opponent: float, skipped_priority: float}
     */
    public function breakdown(array $teamA, array $teamB, int $skipped, PairHistory $history): array
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
     * Lower cost wins; ties go to the lower skipped-priority, then the
     * lexicographically smaller sorted id list.
     *
     * @param  array<int>  $ids
     * @param  array<int>  $bestIds
     */
    public function isBetter(float $cost, int $skipped, array $ids, float $bestCost, int $bestSkipped, array $bestIds): bool
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
