<?php

namespace App\Domain\Rotation;

/**
 * The waiting player chosen to fill an open slot, with the resulting teams.
 */
final readonly class ReplacementResult
{
    /**
     * @param  array<int>  $teamA  player ids after the replacement
     * @param  array<int>  $teamB  player ids after the replacement
     * @param  array{star_balance: float, repeat_partner: float, repeat_opponent: float, skipped_priority: float}  $breakdown
     */
    public function __construct(
        public int $playerId,
        public array $teamA,
        public array $teamB,
        public float $cost,
        public array $breakdown,
    ) {}
}
