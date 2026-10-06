<?php

use App\Domain\Stats\RankedRow;
use App\Domain\Stats\Ranker;
use App\Domain\Stats\StatRow;

function sr(int $id, string $name, int $played, int $wins, int $pf = 0, int $pa = 0): StatRow
{
    return new StatRow($id, $name, $played, $wins, $pf, $pa);
}

/**
 * @param  list<RankedRow>  $rows
 * @return list<string>
 */
function ranks(array $rows): array
{
    return array_map(fn (RankedRow $r): string => $r->row->name.':'.($r->rank ?? '-'), $rows);
}

it('ranks a session by wins then win percent then point diff then name', function () {
    $rows = [
        sr(1, 'Cy', 4, 2, 40, 40),
        sr(2, 'Al', 2, 2, 22, 10),
        sr(3, 'Bo', 3, 2, 33, 30),
        sr(4, 'Di', 3, 3, 33, 20),
        sr(5, 'Ed', 4, 2, 44, 30),
    ];

    expect(ranks((new Ranker)->session($rows)))->toBe(['Di:1', 'Al:2', 'Bo:3', 'Ed:4', 'Cy:5']);
});

it('gives shared competition ranks and orders ties by name', function () {
    $rows = [sr(1, 'Zed', 2, 1, 10, 10), sr(2, 'amy', 2, 1, 10, 10), sr(3, 'Bob', 2, 2, 22, 5), sr(4, 'Cat', 2, 0)];

    expect(ranks((new Ranker)->session($rows)))->toBe(['Bob:1', 'amy:2', 'Zed:2', 'Cat:4']);
});

it('has no minimum games in session mode', function () {
    expect(ranks((new Ranker)->session([sr(1, 'A', 1, 1)])))->toBe(['A:1']);
});

it('ranks the leaderboard by win percent first, exactly', function () {
    // 7/10 = 70% beats 20/30 and 2/3 (both 66.7%); those split on wins.
    $rows = [sr(1, 'A', 10, 7), sr(2, 'B', 3, 2), sr(3, 'C', 30, 20)];

    expect(ranks((new Ranker)->leaderboard($rows, 1)['ranked']))->toBe(['A:1', 'C:2', 'B:3']);
});

it('treats equal fractions as tied on win percent and separates them by wins', function () {
    $out = (new Ranker)->leaderboard([sr(1, 'A', 3, 1), sr(2, 'B', 6, 2)], 1);

    expect(ranks($out['ranked']))->toBe(['B:1', 'A:2']);
});

it('shares a leaderboard rank only when win percent, wins and point diff all tie', function () {
    $rows = [sr(1, 'B', 10, 5, 100, 90), sr(2, 'A', 10, 5, 100, 90), sr(3, 'C', 10, 5, 100, 95)];

    expect(ranks((new Ranker)->leaderboard($rows, 10)['ranked']))->toBe(['A:1', 'B:1', 'C:3']);
});

it('moves players under the minimum to unranked sorted by played then name', function () {
    $rows = [sr(1, 'A', 9, 9), sr(2, 'Zoe', 5, 5), sr(3, 'Bea', 5, 1), sr(4, 'Max', 12, 6), sr(5, 'Low', 1, 0)];

    $out = (new Ranker)->leaderboard($rows, 10);

    expect(ranks($out['ranked']))->toBe(['Max:1'])
        ->and(ranks($out['unranked']))->toBe(['A:-', 'Bea:-', 'Zoe:-', 'Low:-']);
});

it('handles empty input and zero games', function () {
    $r = new Ranker;

    expect($r->session([]))->toBe([])
        ->and($r->leaderboard([], 5))->toBe(['ranked' => [], 'unranked' => []])
        ->and(sr(1, 'A', 0, 0)->winPercent())->toBe(0);
});

it('derives losses, point diff and a whole-number win percent', function () {
    $row = sr(1, 'A', 3, 2, 30, 25);

    expect($row->losses())->toBe(1)->and($row->pointDiff())->toBe(5)->and($row->winPercent())->toBe(67);
});
