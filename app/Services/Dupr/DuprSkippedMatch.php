<?php

namespace App\Services\Dupr;

use App\Models\GameMatch;
use App\Models\Player;

/**
 * A done match that is not exported, with the reason. `missingPlayers` is filled only
 * for MissingDuprId (the players without a DUPR ID in this match).
 */
final readonly class DuprSkippedMatch
{
    /**
     * @param  list<Player>  $missingPlayers
     */
    public function __construct(
        public GameMatch $match,
        public DuprSkipReason $reason,
        public array $missingPlayers = [],
    ) {}

    /** @return list<string> Full names of the players missing a DUPR ID. */
    public function missingNames(): array
    {
        return array_map(fn (Player $p): string => $p->name, $this->missingPlayers);
    }
}
