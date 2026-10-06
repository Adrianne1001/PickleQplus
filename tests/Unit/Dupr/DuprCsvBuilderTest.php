<?php

use App\Domain\Dupr\DuprCsvBuilder;
use App\Domain\Dupr\DuprCsvRow;

const DUPR_TEMPLATE = __DIR__.'/../../../docs/dupr/doubles-match-import.csv';

function duprTemplate(): string
{
    return (string) file_get_contents(DUPR_TEMPLATE);
}

function duprRow(string $event = 'Night', string $location = 'club', string $a1 = 'Ann', array $games = [[11, 4]]): DuprCsvRow
{
    return new DuprCsvRow($event, '2026-09-29', [$a1, 'AAAAAA'], ['Bob', 'BBBBBB'], ['Cy', 'CCCCCC'], ['Di', 'DDDDDD'], $location, $games);
}

/** @return list<string> */
function duprLines(string $csv): array
{
    return explode("\n", $csv);
}

test('the header equals the first line of the official doubles template', function () {
    $first = explode("\n", duprTemplate())[0];

    expect(implode(',', DuprCsvBuilder::HEADER))->toBe($first)
        ->and(DuprCsvBuilder::HEADER)->toHaveCount(27);
});

test('golden file: rows reproduce the official template byte for byte', function () {
    $rows = [];
    foreach ([[1, 4, 7, 10, 0, 11], [2, 5, 8, 11, 11, 4], [3, 6, 9, 12, 11, 8]] as [$a1, $a2, $b1, $b2, $sa, $sb]) {
        $p = fn (int $n): array => ['Test Player '.$n, '[DUPR ID]'];
        $rows[] = new DuprCsvRow('PickleBuddies DUPR Night', '2026-09-29', $p($a1), $p($a2), $p($b1), $p($b2), 'picklebuddies', [[$sa, $sb]]);
    }

    expect((new DuprCsvBuilder)->build($rows))->toBe(duprTemplate());
});

test('output ends with one trailing LF, uses LF only and has no BOM', function () {
    $csv = (new DuprCsvBuilder)->build([duprRow()]);

    expect($csv)->toEndWith("\n")->not->toEndWith("\n\n")
        ->and($csv)->not->toContain("\r")
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeFalse()
        ->and(duprLines($csv))->toHaveCount(3);
});

test('a header only file is produced for no rows', function () {
    expect((new DuprCsvBuilder)->build([]))->toBe(implode(',', DuprCsvBuilder::HEADER)."\n");
});

test('commas in the location and event are quoted', function () {
    $csv = (new DuprCsvBuilder)->build([duprRow(event: 'Night, late', location: 'Court A, Main St')]);

    expect(duprLines($csv)[1])->toContain('"Night, late"')->toContain('"Court A, Main St"');
});

test('quotes in a name are doubled and the field quoted', function () {
    $csv = (new DuprCsvBuilder)->build([duprRow(a1: 'Ann "AJ" Lee')]);

    expect(duprLines($csv)[1])->toContain('"Ann ""AJ"" Lee"');
});

test('newlines in a field are quoted', function () {
    $csv = (new DuprCsvBuilder)->build([duprRow(event: "Two\nLines")]);

    expect($csv)->toContain("\"Two\nLines\"");
});

test('spaces alone do not cause quoting', function () {
    $csv = (new DuprCsvBuilder)->build([duprRow(event: 'Open Play Night', a1: 'Ann Marie Lee')]);

    expect(duprLines($csv)[1])->toStartWith('D,Open Play Night,2026-09-29,Ann Marie Lee,AAAAAA,,')
        ->and($csv)->not->toContain('"');
});

test('games 2 to 5 are blank and external ids are blank', function () {
    $line = duprLines((new DuprCsvBuilder)->build([duprRow()]))[1];
    $fields = explode(',', $line);

    expect($fields)->toHaveCount(27)
        ->and(array_slice($fields, 17))->toBe(['11', '4', '', '', '', '', '', '', '', ''])
        ->and([$fields[5], $fields[8], $fields[11], $fields[14]])->toBe(['', '', '', ''])
        ->and([$fields[0], $fields[16]])->toBe(['D', 'SIDEOUT']);
});

test('a zero score is written as 0, not blank', function () {
    $fields = explode(',', duprLines((new DuprCsvBuilder)->build([duprRow(games: [[0, 11]])]))[1]);

    expect(array_slice($fields, 17, 2))->toBe(['0', '11']);
});
