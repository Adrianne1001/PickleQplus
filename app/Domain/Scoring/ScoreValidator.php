<?php

namespace App\Domain\Scoring;

/**
 * Pure side-out score validation (PLAN.md "Valid score"). No framework use.
 *
 * No ties. The winner has at least `to` points and leads by at least `win_by`.
 * If the winner has more than `to`, the lead must be exactly `win_by`
 * (so for to 11, win by 2: 11-9 and 12-10 are valid; 11-10, 13-10, 10-8 are not).
 */
final class ScoreValidator
{
    /**
     * @param  array{to: int, win_by: int}|array<string, mixed>  $scoring
     * @return string|null an error message, or null when the score is valid
     */
    public static function error(array $scoring, int $teamA, int $teamB): ?string
    {
        $to = (int) ($scoring['to'] ?? 11);
        $winBy = (int) ($scoring['win_by'] ?? 2);

        if ($teamA < 0 || $teamB < 0) {
            return 'Scores cannot be negative.';
        }

        if ($teamA === $teamB) {
            return 'A match cannot end in a tie.';
        }

        $winner = max($teamA, $teamB);
        $margin = $winner - min($teamA, $teamB);

        if ($winner < $to) {
            return "The winner needs at least {$to} points.";
        }

        if ($margin < $winBy) {
            return "The winner must lead by at least {$winBy}.";
        }

        if ($winner > $to && $margin !== $winBy) {
            return "Past {$to} points the winner must lead by exactly {$winBy}.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scoring
     */
    public static function isValid(array $scoring, int $teamA, int $teamB): bool
    {
        return self::error($scoring, $teamA, $teamB) === null;
    }
}
