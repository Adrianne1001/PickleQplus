<?php

namespace App\Services\Dupr;

use App\Models\GameMatch;
use Illuminate\Support\Collection;

/**
 * Eligibility of a session's done matches for DUPR export.
 *
 * - `eligible`: matches that would be exported, oldest first, with matchPlayers.player loaded.
 * - `skipped`: every other done match with its reason (void/staged/playing are not listed).
 * - `missingPlayers`: distinct players without a DUPR ID, most blocked matches first. Counts
 *   only matches skipped for MissingDuprId.
 */
final readonly class DuprEligibilitySummary
{
    /**
     * @param  Collection<int, GameMatch>  $eligible
     * @param  list<DuprSkippedMatch>  $skipped
     * @param  list<DuprMissingPlayer>  $missingPlayers
     */
    public function __construct(
        public Collection $eligible,
        public array $skipped,
        public array $missingPlayers,
    ) {}

    public function eligibleCount(): int
    {
        return $this->eligible->count();
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function hasEligible(): bool
    {
        return $this->eligible->isNotEmpty();
    }

    /** @return list<DuprSkippedMatch> */
    public function skippedFor(DuprSkipReason $reason): array
    {
        return array_values(array_filter($this->skipped, fn (DuprSkippedMatch $s): bool => $s->reason === $reason));
    }
}
