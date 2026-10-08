<?php

namespace App\Services\Share;

/** One podium spot. Names only: no ids and no DUPR data ever reach an image. */
final readonly class PodiumEntry
{
    public function __construct(
        public int $rank,
        public string $name,
        public int $wins,
        public int $losses,
        public int $winPct,
    ) {}
}
