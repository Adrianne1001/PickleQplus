<?php

use App\Domain\Rotation\WaitEstimator;

it('is zero when a court is idle and the player is in the next match', function () {
    expect((new WaitEstimator)->estimate(0, 2, 0, [], 15))->toBe(0);
});

it('waits for the soonest court when all are playing', function () {
    // Remaining: 10, 5, 15, 1.
    expect((new WaitEstimator)->estimate(0, 4, 0, [5, 10, 0, 14], 15))->toBe(1);
});

it('groups the queue in fours', function () {
    $e = new WaitEstimator;

    expect($e->estimate(3, 4, 0, [5, 10, 0, 14], 15))->toBe(1)
        ->and($e->estimate(4, 4, 0, [5, 10, 0, 14], 15))->toBe(5)
        ->and($e->estimate(8, 4, 0, [5, 10, 0, 14], 15))->toBe(10);
});

it('serves staged matches first', function () {
    expect((new WaitEstimator)->estimate(0, 4, 1, [5, 10, 0, 14], 15))->toBe(5);
});

it('reuses a single court after the average duration', function () {
    $e = new WaitEstimator;

    expect($e->estimate(0, 1, 2, [], 15))->toBe(30)
        ->and($e->estimate(4, 1, 0, [], 15))->toBe(15);
});

it('treats overdue matches as freeing now', function () {
    expect((new WaitEstimator)->estimate(0, 1, 0, [40], 15))->toBe(0);
});

it('rounds up to whole minutes', function () {
    expect((new WaitEstimator)->estimate(0, 1, 0, [10.5], 15))->toBe(5);
});

it('returns 0 when there are no courts at all', function () {
    expect((new WaitEstimator)->estimate(0, 0, 0, [], 15))->toBe(0);
});
