<?php

namespace App\Domain\Rotation;

/**
 * How often each unordered player pair has been partners or opponents
 * in the session so far.
 */
final readonly class PairHistory
{
    /**
     * @param  array<string, int>  $partners  keyed by "lowId:highId"
     * @param  array<string, int>  $opponents  keyed by "lowId:highId"
     */
    public function __construct(
        private array $partners = [],
        private array $opponents = [],
    ) {}

    /**
     * Build from matches, each given as [[a, b], [c, d]] (team A ids, team B ids).
     *
     * @param  iterable<array{0: array<int>, 1: array<int>}>  $matches
     */
    public static function fromMatches(iterable $matches): self
    {
        $partners = [];
        $opponents = [];

        foreach ($matches as [$teamA, $teamB]) {
            foreach ([$teamA, $teamB] as $team) {
                $count = count($team);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $key = self::key($team[$i], $team[$j]);
                        $partners[$key] = ($partners[$key] ?? 0) + 1;
                    }
                }
            }
            foreach ($teamA as $a) {
                foreach ($teamB as $b) {
                    $key = self::key($a, $b);
                    $opponents[$key] = ($opponents[$key] ?? 0) + 1;
                }
            }
        }

        return new self($partners, $opponents);
    }

    public function partnerCount(int $a, int $b): int
    {
        return $this->partners[self::key($a, $b)] ?? 0;
    }

    public function opponentCount(int $a, int $b): int
    {
        return $this->opponents[self::key($a, $b)] ?? 0;
    }

    private static function key(int $a, int $b): string
    {
        return $a < $b ? "$a:$b" : "$b:$a";
    }
}
