<?php

namespace App\Domain\PlayerList;

/** The confirmed players of a Reclub event, in sign-up order. */
final class ReclubEvent
{
    /**
     * @param  list<string>  $players  Names of confirmed participants (status 1).
     */
    public function __construct(
        public readonly array $players,
        public readonly int $waitlisted = 0,
        public readonly int $cancelled = 0,
    ) {}
}
