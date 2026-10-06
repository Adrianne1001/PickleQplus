<?php

use App\Domain\Scoring\ScoreValidator;

it('validates to 11 win by 2', function (int $a, int $b, bool $valid) {
    expect(ScoreValidator::isValid(['to' => 11, 'win_by' => 2], $a, $b))->toBe($valid);
})->with([
    [11, 0, true], [11, 9, true], [12, 10, true], [15, 13, true], [9, 11, true],
    [11, 10, false], [13, 10, false], [10, 8, false], [11, 11, false], [0, 0, false],
    [-1, 11, false], [12, 11, false], [14, 10, false],
]);

it('validates win by 1', function (int $a, int $b, bool $valid) {
    expect(ScoreValidator::isValid(['to' => 11, 'win_by' => 1], $a, $b))->toBe($valid);
})->with([
    [11, 10, true], [12, 11, true], [13, 11, false], [10, 9, false], [11, 11, false],
]);

it('honours to 15 and 21', function () {
    expect(ScoreValidator::isValid(['to' => 15, 'win_by' => 2], 15, 13))->toBeTrue()
        ->and(ScoreValidator::isValid(['to' => 15, 'win_by' => 2], 11, 3))->toBeFalse()
        ->and(ScoreValidator::isValid(['to' => 21, 'win_by' => 2], 21, 19))->toBeTrue()
        ->and(ScoreValidator::isValid(['to' => 21, 'win_by' => 2], 20, 10))->toBeFalse();
});

it('returns a message for invalid scores and null for valid ones', function () {
    expect(ScoreValidator::error(['to' => 11, 'win_by' => 2], 11, 9))->toBeNull()
        ->and(ScoreValidator::error(['to' => 11, 'win_by' => 2], 11, 11))->toBeString();
});
