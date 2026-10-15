<?php

namespace App\Domain\PlayerList;

/**
 * One player parsed from pasted text or a Reclub page. Raw strings only: validation and matching
 * happen later, in the import service.
 */
final class PastedPlayer
{
    /**
     * @param  int  $line  1-based line in the pasted text (0 for a Reclub row).
     * @param  string  $rating  Raw `rating=` value, '' when absent.
     * @param  string  $duprId  Raw DUPR ID value, '' when absent.
     * @param  list<string>  $warnings
     * @param  string  $gender  `man` or `woman` when the `gender=` value was recognised, its raw text when not, '' when absent.
     */
    public function __construct(
        public readonly int $line,
        public readonly string $name,
        public readonly string $rating = '',
        public readonly string $duprId = '',
        public readonly array $warnings = [],
        public readonly string $gender = '',
    ) {}
}
