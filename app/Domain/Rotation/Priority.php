<?php

namespace App\Domain\Rotation;

/**
 * The queue priority order shared by every engine: fewest effective games,
 * then longest wait (earliest queued_at), then lowest id.
 */
final class Priority
{
    /**
     * @param  array<Candidate>  $candidates
     * @return list<Candidate>
     */
    public static function sort(array $candidates): array
    {
        $sorted = array_values($candidates);
        usort($sorted, static fn (Candidate $a, Candidate $b): int => [$a->effectiveGames, $a->queuedAt, $a->id] <=> [$b->effectiveGames, $b->queuedAt, $b->id]);

        return $sorted;
    }
}
