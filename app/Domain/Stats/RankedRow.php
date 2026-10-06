<?php

namespace App\Domain\Stats;

/**
 * A row with its shared competition rank (1, 2, 2, 4). Rank is null for rows
 * under the leaderboard minimum ("not ranked yet").
 */
final readonly class RankedRow
{
    public function __construct(
        public ?int $rank,
        public StatRow $row,
    ) {}
}
