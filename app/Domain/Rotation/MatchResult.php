<?php

namespace App\Domain\Rotation;

/**
 * The engine's chosen match.
 */
final readonly class MatchResult
{
    /**
     * @param  array{0: int, 1: int}  $teamA  player ids
     * @param  array{0: int, 1: int}  $teamB  player ids
     * @param  array{star_balance: float, repeat_partner: float, repeat_opponent: float, skipped_priority: float}  $breakdown  weighted cost per term
     */
    public function __construct(
        public array $teamA,
        public array $teamB,
        public float $cost,
        public array $breakdown,
    ) {}
}
