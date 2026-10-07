<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\ReplacementResult;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Validation\ValidationException;

/**
 * One rotation mode (PLAN.md section 2, Phase 7 rules). MatchService owns the
 * transaction, the session lock and the shared finish/void/undo steps, and
 * calls these hooks inside that lock. A strategy only decides who is staged;
 * it never opens a transaction or fires events.
 */
interface RotationStrategy
{
    /**
     * Staging half of MatchService::refill(): void staged matches beyond the
     * mode's limit, then fill empty Up Next slots from the waiting players.
     */
    public function fillUpNext(PlaySession $session, RotationQueries $queries): void;

    /**
     * Stage a replacement for a staged match that was just voided. The previous
     * player ids are the players of the voided match. Stages nothing when no
     * other group exists (the caller's refill then re-stages the same players).
     *
     * @param  list<int>  $previousPlayerIds
     */
    public function reroll(PlaySession $session, RotationQueries $queries, array $previousPlayerIds, GameMatch $voided): void;

    /**
     * The courts a staged match may start on (every court in most modes, the match's
     * group in skill courts). Start and auto_fill never use a court outside this list.
     *
     * @return list<int>
     */
    public function allowedCourts(PlaySession $session, GameMatch $match): array;

    /**
     * The waiting player who best fills the open slot of a match, given the
     * players who stay (already split by team). Null when nobody is available.
     *
     * @param  list<Candidate>  $teamA
     * @param  list<Candidate>  $teamB
     */
    public function pickReplacement(PlaySession $session, RotationQueries $queries, array $teamA, array $teamB, int $exceptMatchId): ?ReplacementResult;

    /**
     * Reject a swap the mode does not allow (for example a different gender in
     * mixed doubles) by throwing a ValidationException. Runs before the swap is applied.
     *
     * @throws ValidationException
     */
    public function guardSwap(PlaySession $session, GameMatch $match, Player $out, Player $in): void;

    /**
     * Called after a match was finished and its players are waiting again,
     * before the refill. Modes that route winners (P7.4d, P7.4e) hook in here.
     */
    public function afterFinish(PlaySession $session, RotationQueries $queries, GameMatch $match): void;

    /**
     * Waiting players who must not be staged into an ordinary match because
     * the mode has reserved them (pending players in P7.4d and P7.4e).
     *
     * @return list<int>
     */
    public function reservedPlayerIds(PlaySession $session, RotationQueries $queries): array;
}
