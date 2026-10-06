<?php

namespace App\Domain\Stats;

/**
 * Pure ranking of per-player stat rows. Win % is compared exactly by
 * cross-multiplying, never as floats. Rows tied on every ranking key share a
 * rank (1, 2, 2, 4) and are listed by name inside the tie.
 */
final class Ranker
{
    /**
     * Session standings: wins, then win %, then point diff. No minimum.
     *
     * @param  list<StatRow>  $rows
     * @return list<RankedRow>
     */
    public function session(array $rows): array
    {
        return $this->rank($rows, fn (StatRow $a, StatRow $b): int => $this->compareSession($a, $b));
    }

    /**
     * Leaderboard: win %, then wins, then point diff. Rows under $minGames are
     * returned separately, unranked, by games played desc then name.
     *
     * @param  list<StatRow>  $rows
     * @return array{ranked: list<RankedRow>, unranked: list<RankedRow>}
     */
    public function leaderboard(array $rows, int $minGames): array
    {
        $eligible = [];
        $rest = [];
        foreach ($rows as $row) {
            if ($row->played >= $minGames) {
                $eligible[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        usort($rest, fn (StatRow $a, StatRow $b): int => ($b->played <=> $a->played) ?: $this->compareName($a, $b));

        return [
            'ranked' => $this->rank($eligible, fn (StatRow $a, StatRow $b): int => $this->compareLeaderboard($a, $b)),
            'unranked' => array_map(fn (StatRow $r): RankedRow => new RankedRow(null, $r), $rest),
        ];
    }

    /**
     * @param  list<StatRow>  $rows
     * @param  callable(StatRow, StatRow): int  $keys  Ranking keys only; 0 means tied.
     * @return list<RankedRow>
     */
    private function rank(array $rows, callable $keys): array
    {
        usort($rows, fn (StatRow $a, StatRow $b): int => $keys($a, $b) ?: $this->compareName($a, $b));

        $out = [];
        $rank = 0;
        foreach ($rows as $i => $row) {
            if ($i === 0 || $keys($rows[$i - 1], $row) !== 0) {
                $rank = $i + 1;
            }
            $out[] = new RankedRow($rank, $row);
        }

        return $out;
    }

    private function compareSession(StatRow $a, StatRow $b): int
    {
        return ($b->wins <=> $a->wins)
            ?: $this->compareWinRate($a, $b)
            ?: ($b->pointDiff() <=> $a->pointDiff());
    }

    private function compareLeaderboard(StatRow $a, StatRow $b): int
    {
        return $this->compareWinRate($a, $b)
            ?: ($b->wins <=> $a->wins)
            ?: ($b->pointDiff() <=> $a->pointDiff());
    }

    /** Higher win rate first (negative when $a is better), exactly. */
    private function compareWinRate(StatRow $a, StatRow $b): int
    {
        // wins/played with played = 0 treated as 0/1 so the order stays transitive.
        return ($b->wins * max($a->played, 1)) <=> ($a->wins * max($b->played, 1));
    }

    private function compareName(StatRow $a, StatRow $b): int
    {
        return strcasecmp($a->name, $b->name) ?: strcmp($a->name, $b->name) ?: strcmp((string) $a->id, (string) $b->id);
    }
}
