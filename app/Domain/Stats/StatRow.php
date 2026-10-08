<?php

namespace App\Domain\Stats;

/**
 * One player's totals over a set of done matches. Plain data, no framework.
 */
final readonly class StatRow
{
    public function __construct(
        public int|string $id,
        public string $name,
        public int $played,
        public int $wins,
        public int $pointsFor,
        public int $pointsAgainst,
    ) {}

    public function losses(): int
    {
        return $this->played - $this->wins;
    }

    public function pointDiff(): int
    {
        return $this->pointsFor - $this->pointsAgainst;
    }

    /** Win percentage as a whole number (0-100); 0 when nothing was played. */
    public function winPercent(): int
    {
        return $this->played === 0 ? 0 : (int) round($this->wins * 100 / $this->played);
    }
}
