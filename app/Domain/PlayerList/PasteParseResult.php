<?php

namespace App\Domain\PlayerList;

/** Rows from a pasted list, or an error that makes the whole paste unusable. */
final class PasteParseResult
{
    /**
     * @param  list<PastedPlayer>  $players
     */
    public function __construct(
        public readonly array $players,
        public readonly ?string $error = null,
    ) {}
}
