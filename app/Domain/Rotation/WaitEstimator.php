<?php

namespace App\Domain\Rotation;

/**
 * Estimates minutes until a waiting player starts playing. Pure.
 *
 * Model: staged matches start first, in order, then the unstaged queue in
 * groups of 4. A player at queue position p (0 = next unstaged player) is in
 * start-order match number S + floor(p / 4), where S is the staged count.
 * Courts become free at: now for each idle court, and (average - elapsed,
 * floored at 0) for each playing match. Each match starts on the earliest
 * free court, and that court is busy for the average duration afterwards.
 * The result is the start time of the player's match, rounded up to whole minutes.
 */
final class WaitEstimator
{
    private const EPSILON = 1e-9;

    /**
     * @param  int  $queuePosition  0 = next unstaged waiting player
     * @param  int  $courts  total courts in the session
     * @param  int  $stagedCount  staged matches waiting for a court
     * @param  array<int|float>  $playingElapsedMinutes  minutes elapsed in each playing match
     * @param  float  $averageMatchMinutes  average match duration
     */
    public function estimate(
        int $queuePosition,
        int $courts,
        int $stagedCount,
        array $playingElapsedMinutes,
        float $averageMatchMinutes,
    ): int {
        $matchIndex = max(0, $stagedCount) + intdiv(max(0, $queuePosition), 4);

        return $this->estimateFromMatchIndex($matchIndex, $courts, $playingElapsedMinutes, $averageMatchMinutes);
    }

    /**
     * Minutes until the match at start-order index $matchIndex (0 = first match
     * to start, staged matches first) gets a court.
     *
     * @param  int  $matchIndex  0-based position in the start order
     * @param  int  $courts  total courts in the session
     * @param  array<int|float>  $playingElapsedMinutes  minutes elapsed in each playing match
     * @param  float  $averageMatchMinutes  average match duration
     */
    public function estimateFromMatchIndex(
        int $matchIndex,
        int $courts,
        array $playingElapsedMinutes,
        float $averageMatchMinutes,
    ): int {
        $matchIndex = max(0, $matchIndex);
        $average = max(0.0, $averageMatchMinutes);
        $free = [];
        foreach ($playingElapsedMinutes as $elapsed) {
            $free[] = max(0.0, $average - $elapsed);
        }
        for ($i = count($playingElapsedMinutes); $i < $courts; $i++) {
            $free[] = 0.0;
        }
        if ($free === []) {
            return 0;
        }

        $start = 0.0;
        for ($m = 0; $m <= $matchIndex; $m++) {
            sort($free);
            $start = $free[0];
            $free[0] = $start + $average;
        }

        return (int) ceil($start - self::EPSILON);
    }
}
