<?php

namespace App\Domain\Dupr;

/**
 * Pure builder for DUPR's doubles match import CSV (docs/dupr/doubles-match-import.csv).
 * Plain rows in, CSV string out: no Eloquent, no facades.
 *
 * Format: UTF-8 without BOM, comma delimiter, LF endings, trailing newline. A field is
 * quoted only when it contains a comma, double quote, CR or LF (inner quotes doubled).
 * PHP's fputcsv is not used because it also quotes fields containing spaces.
 */
final class DuprCsvBuilder
{
    /**
     * Header row, copied from the official template. A golden test compares it with the
     * first line of docs/dupr/doubles-match-import.csv.
     *
     * @var list<string>
     */
    public const HEADER = [
        'matchType',
        'event',
        'date',
        'playerA1',
        'playerA1DuprId',
        'playerA1ExternalId',
        'playerA2',
        'playerA2DuprId',
        'playerA2ExternalId',
        'playerB1',
        'playerB1DuprId',
        'playerB1ExternalId',
        'playerB2',
        'playerB2DuprId',
        'playerB2ExternalId',
        'location',
        'scoreType',
        'teamAGame1',
        'teamBGame1',
        'teamAGame2',
        'teamBGame2',
        'teamAGame3',
        'teamBGame3',
        'teamAGame4',
        'teamBGame4',
        'teamAGame5',
        'teamBGame5',
    ];

    /**
     * @param  iterable<DuprCsvRow>  $rows
     */
    public function build(iterable $rows): string
    {
        $out = $this->line(self::HEADER);
        foreach ($rows as $row) {
            $out .= $this->line($row->toFields());
        }

        return $out;
    }

    /**
     * @param  list<string>  $fields
     */
    private function line(array $fields): string
    {
        return implode(',', array_map($this->field(...), $fields))."\n";
    }

    private function field(string $value): string
    {
        if (strpbrk($value, ",\"\r\n") === false) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
