<?php

namespace App\Domain\Rotation;

use App\Enums\Gender;

/**
 * A waiting player as seen by the rotation engine.
 */
final readonly class Candidate
{
    public function __construct(
        public int $id,
        public int $stars,
        public int $effectiveGames,
        public int $queuedAt,
        public ?Gender $gender = null,
    ) {}
}
