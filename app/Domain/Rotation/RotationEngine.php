<?php

namespace App\Domain\Rotation;

interface RotationEngine
{
    /**
     * Pick the next match from the waiting players, or null if fewer than 4.
     *
     * @param  array<Candidate>  $candidates
     */
    public function pickMatch(array $candidates, PairHistory $history): ?MatchResult;

    /**
     * Fill the open slot of a match that has 3 players left. The team holding
     * one player is the team with the open slot. Returns null if there are no candidates.
     *
     * @param  array<Candidate>  $teamA  remaining players on team A (1 or 2)
     * @param  array<Candidate>  $teamB  remaining players on team B (1 or 2)
     * @param  array<Candidate>  $candidates  waiting players eligible to fill it
     */
    public function pickReplacement(array $teamA, array $teamB, array $candidates, PairHistory $history): ?ReplacementResult;
}
