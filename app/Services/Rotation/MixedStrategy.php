<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\ReplacementResult;
use App\Domain\Rotation\RotationEngine;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Validation\ValidationException;

/**
 * Mixed doubles: the balanced flow with the mixed engine. Every team is one man
 * and one woman. Players with no gender are never staged, and a slot that
 * cannot get 2 men and 2 women waits (there is no fallback).
 */
class MixedStrategy extends BalancedStrategy
{
    public function __construct(RotationEngine $engine, private readonly BalancedRotationEngine $balanced)
    {
        parent::__construct($engine);
    }

    protected function waiting(PlaySession $session, RotationQueries $queries): array
    {
        return array_values(array_filter(
            parent::waiting($session, $queries),
            static fn (Candidate $c): bool => $c->gender !== null,
        ));
    }

    public function pickReplacement(PlaySession $session, RotationQueries $queries, array $teamA, array $teamB, int $exceptMatchId): ?ReplacementResult
    {
        // A match that was playing before the session switched to mixed can hold a player with no
        // gender. There is no gender to match against then, so the best gendered waiting player fills it.
        $lone = count($teamA) === 1 ? $teamA[0] : (count($teamB) === 1 ? $teamB[0] : null);
        if ($lone !== null && $lone->gender === null) {
            return $this->balanced->pickReplacement($teamA, $teamB, $this->waiting($session, $queries), $queries->history($exceptMatchId));
        }

        return parent::pickReplacement($session, $queries, $teamA, $teamB, $exceptMatchId);
    }

    public function guardSwap(PlaySession $session, GameMatch $match, Player $out, Player $in): void
    {
        if ($in->gender === null || ($out->gender !== null && $in->gender !== $out->gender)) {
            throw ValidationException::withMessages([
                'player' => 'Mixed doubles: swap in a player of the same gender as the player leaving.',
            ]);
        }
    }
}
