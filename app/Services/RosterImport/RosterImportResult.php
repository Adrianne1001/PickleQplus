<?php

namespace App\Services\RosterImport;

final readonly class RosterImportResult
{
    /**
     * @param  int  $errors  Rows left out: preview errors plus rows that conflicted at commit time.
     * @param  list<string>  $notes  Commit-time problems, e.g. "Line 7: DUPR ID is now used by another player".
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public int $errors,
        public array $notes = [],
    ) {}
}
