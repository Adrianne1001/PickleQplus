<?php

namespace App\Livewire\Sessions\Concerns;

use App\Domain\Scoring\ScoreValidator;
use App\Enums\Team;

/**
 * Live feedback for a result form: the score rule, then agreement with the chosen winner.
 * Only a hint, the service validates again on submit.
 */
trait HintsScore
{
    protected function resultHint(string $scoreA, string $scoreB, string $winner): ?string
    {
        if (! ctype_digit($scoreA) || ! ctype_digit($scoreB)) {
            return null;
        }

        $error = ScoreValidator::error($this->session->scoring, (int) $scoreA, (int) $scoreB);
        if ($error !== null) {
            return $error;
        }

        $team = Team::tryFrom($winner);
        if ($team === null) {
            return null;
        }
        $higher = (int) $scoreA > (int) $scoreB ? Team::A : Team::B;
        if ($higher === $team) {
            return null;
        }

        return __("Team :team's score must be higher.", ['team' => $team->value]);
    }
}
