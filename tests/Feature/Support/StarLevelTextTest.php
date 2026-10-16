<?php

use App\Domain\Stars\StarLevels;
use App\Domain\Stars\StarRating;
use App\Models\Club;
use App\Support\StarLevelText;

/** @return list<string> */
function starRangesOf(array $bands): array
{
    return array_map(fn (array $l): string => StarLevelText::range($l), StarLevels::forBands($bands));
}

test('the ranges read below, a range with an en dash, and plus for the default bands', function () {
    expect(starRangesOf(StarRating::DEFAULT_BANDS))->toBe(['below 2.50', '2.50–2.99', '3.00–3.49', '3.50–3.99', '4.00–4.49', '4.50+']);
});

test('the ranges follow custom bands', function () {
    expect(starRangesOf([2.0, 2.75, 3.25, 4.0, 5.0]))->toBe(['below 2.00', '2.00–2.74', '2.75–3.24', '3.25–3.99', '4.00–4.99', '5.00+']);
});

test('bands that are not on the 0.01 grid show three decimals and under', function () {
    expect(starRangesOf([2.5, 3.005, 3.5, 4.0, 4.5]))
        ->toBe(['below 2.50', '2.500 to under 3.005', '3.005–3.490', '3.50–3.99', '4.00–4.49', '4.50+']);
});

test('finer bands get as many decimals as they need', function () {
    $levels = StarLevels::forBands([2.5, 3.0, 3.5, 4.0, 4.2505]);

    expect(StarLevelText::range($levels[4]))->toBe('4.0000 to under 4.2505')
        ->and(StarLevelText::range($levels[5]))->toBe('4.2505+');
});

test('the ranges come from the club bands', function () {
    $club = new Club(['star_bands' => [2.0, 2.75, 3.25, 4.0, 5.0]]);

    expect(StarLevelText::range(StarLevels::forClub($club)[2]))->toBe('2.75–3.24');
});

test('the DUPR line, title and detail read the same as before for the default bands', function () {
    $levels = StarLevels::forBands();

    expect(array_map(StarLevelText::dupr(...), $levels))
        ->toBe(['DUPR below 2.50', 'DUPR ~2.50–2.99', 'DUPR ~3.00–3.49', 'DUPR ~3.50–3.99', 'DUPR ~4.00–4.49', 'DUPR 4.50+'])
        ->and(StarLevelText::title($levels[2]))->toBe('★3 · Intermediate')
        ->and(StarLevelText::detail($levels[2]))->toBe('Consistent rallies, learning dinks · DUPR ~3.00–3.49');
});
