<?php

namespace App\Services\Dupr;

use App\Models\Player;

/** A player without a DUPR ID and how many of the session's done matches they block. */
final readonly class DuprMissingPlayer
{
    public function __construct(
        public Player $player,
        public int $blockedMatches,
    ) {}
}
