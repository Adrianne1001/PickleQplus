<?php

namespace App\Domain\Dupr;

/**
 * One match row of the DUPR doubles import. Players are [name, duprId] pairs in the
 * order A1, A2, B1, B2. Games are [teamA, teamB] pairs (up to 5; v1 uses one).
 */
final readonly class DuprCsvRow
{
    /**
     * @param  array{0: string, 1: string}  $a1
     * @param  array{0: string, 1: string}  $a2
     * @param  array{0: string, 1: string}  $b1
     * @param  array{0: string, 1: string}  $b2
     * @param  list<array{0: int|null, 1: int|null}>  $games
     */
    public function __construct(
        public string $event,
        public string $date,
        public array $a1,
        public array $a2,
        public array $b1,
        public array $b2,
        public string $location,
        public array $games,
        public string $matchType = 'D',
        public string $scoreType = 'SIDEOUT',
    ) {}

    /**
     * Fields in the exact column order of DuprCsvBuilder::HEADER.
     *
     * @return list<string>
     */
    public function toFields(): array
    {
        $fields = [$this->matchType, $this->event, $this->date];
        foreach ([$this->a1, $this->a2, $this->b1, $this->b2] as [$name, $duprId]) {
            // The ExternalId column is intentionally blank.
            array_push($fields, $name, $duprId, '');
        }
        array_push($fields, $this->location, $this->scoreType);

        for ($i = 0; $i < 5; $i++) {
            $game = $this->games[$i] ?? [null, null];
            array_push($fields, $game[0] === null ? '' : (string) $game[0], $game[1] === null ? '' : (string) $game[1]);
        }

        return $fields;
    }
}
