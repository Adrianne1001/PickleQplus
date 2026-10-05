<?php

use App\Domain\Stars\StarRating;

it('maps ratings to stars using the default bands', function (float $rating, int $stars) {
    expect(StarRating::fromRating($rating))->toBe($stars);
})->with([
    'floor of range' => [2.0, 1],
    'just below 2.50' => [2.499, 1],
    'exactly 2.50' => [2.50, 2],
    'just below 3.00' => [2.999, 2],
    'exactly 3.00' => [3.00, 3],
    'exactly 3.50' => [3.50, 4],
    'exactly 4.00' => [4.00, 5],
    'just below 4.50' => [4.499, 5],
    'exactly 4.50' => [4.50, 6],
    'top of range' => [8.0, 6],
]);

it('uses custom bands', function () {
    $bands = [3.0, 3.5, 4.0, 4.5, 5.0];

    expect(StarRating::fromRating(2.9, $bands))->toBe(1)
        ->and(StarRating::fromRating(3.0, $bands))->toBe(2)
        ->and(StarRating::fromRating(5.0, $bands))->toBe(6);
});

it('accepts valid bands', function () {
    expect(StarRating::validate([2, 3, 4, 5, 6]))->toBe([])
        ->and(StarRating::validate(StarRating::DEFAULT_BANDS))->toBe([]);
});

it('rejects invalid bands', function (array $bands) {
    expect(StarRating::validate($bands))->not->toBe([]);
    expect(fn () => StarRating::fromRating(3.0, $bands))->toThrow(InvalidArgumentException::class);
})->with([
    'too few' => [[2.5, 3.0, 3.5, 4.0]],
    'too many' => [[2.5, 3.0, 3.5, 4.0, 4.5, 5.0]],
    'not ascending' => [[2.5, 3.5, 3.0, 4.0, 4.5]],
    'duplicates' => [[2.5, 3.0, 3.0, 4.0, 4.5]],
    'below range' => [[1.9, 3.0, 3.5, 4.0, 4.5]],
    'above range' => [[2.5, 3.0, 3.5, 4.0, 8.1]],
    'non numeric' => [[2.5, 3.0, 'x', 4.0, 4.5]],
]);

it('allows bands at the range edges', function () {
    expect(StarRating::validate([2.0, 3.0, 4.0, 5.0, 8.0]))->toBe([]);
});
