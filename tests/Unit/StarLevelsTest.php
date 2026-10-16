<?php

use App\Domain\Stars\StarLevels;
use App\Domain\Stars\StarRating;
use App\Models\Club;

it('describes the six levels with bounds from the default bands', function () {
    $levels = StarLevels::forBands();

    expect($levels)->toHaveCount(6)
        ->and(array_column($levels, 'stars'))->toBe([1, 2, 3, 4, 5, 6])
        ->and(array_column($levels, 'label'))->toBe(['Beginner', 'Novice', 'Intermediate', 'Upper intermediate', 'Advanced', 'Expert'])
        ->and(array_column($levels, 'min'))->toBe([null, 2.5, 3.0, 3.5, 4.0, 4.5])
        ->and(array_column($levels, 'max'))->toBe([2.5, 2.99, 3.49, 3.99, 4.49, null])
        ->and(array_column($levels, 'max_inclusive'))->toBe([false, true, true, true, true, false])
        ->and($levels[0]['hint'])->toBe('New to pickleball')
        ->and($levels[5]['hint'])->toBe('Tournament level');
    foreach ($levels as $level) {
        expect($level)->toHaveKeys(['stars', 'label', 'hint', 'min', 'max', 'max_inclusive', 'decimals'])
            ->and($level['hint'])->not->toBe('')
            ->and($level['decimals'])->toBe(2);
    }
});

it('rejects invalid bands', function () {
    expect(fn () => StarLevels::forBands([3.0, 2.0, 4.0, 5.0, 6.0]))->toThrow(InvalidArgumentException::class);
});

it('works the bounds out from custom bands', function () {
    $levels = StarLevels::forBands([2.0, 2.75, 3.25, 4.0, 5.0]);

    expect(array_column($levels, 'min'))->toBe([null, 2.0, 2.75, 3.25, 4.0, 5.0])
        ->and(array_column($levels, 'max'))->toBe([2.0, 2.74, 3.24, 3.99, 4.99, null])
        ->and(array_column($levels, 'decimals'))->toBe([2, 2, 2, 2, 2, 2]);
});

it('uses an exclusive upper bound for bands that are not on the 0.01 grid', function () {
    $levels = StarLevels::forBands([2.5, 3.005, 3.5, 4.0, 4.5]);

    expect($levels[1]['max'])->toBe(3.005)->and($levels[1]['max_inclusive'])->toBeFalse()->and($levels[1]['decimals'])->toBe(3)
        ->and($levels[2]['min'])->toBe(3.005)->and($levels[2]['max'])->toBe(3.49)->and($levels[2]['max_inclusive'])->toBeTrue()->and($levels[2]['decimals'])->toBe(3)
        ->and($levels[3]['decimals'])->toBe(2);
});

it('keeps the ranges consistent with StarRating::fromRating for fine bands', function () {
    $bands = [2.5, 3.005, 3.5, 4.0, 4.5];

    foreach (StarLevels::forBands($bands) as $level) {
        if ($level['min'] !== null) {
            expect(StarRating::fromRating($level['min'], $bands))->toBe($level['stars']);
        }
        if ($level['max'] !== null) {
            $last = $level['max_inclusive'] ? $level['max'] : $level['max'] - 0.0001;
            $next = $level['max_inclusive'] ? $level['max'] + 0.01 : $level['max'];
            expect(StarRating::fromRating($last, $bands))->toBe($level['stars'])
                ->and(StarRating::fromRating($next, $bands))->toBe($level['stars'] + 1);
        }
    }
});

it('reads the bands of a club', function () {
    expect(StarLevels::forClub(new Club(['star_bands' => [2.0, 2.75, 3.25, 4.0, 5.0]]))[2]['min'])->toBe(2.75);
});
