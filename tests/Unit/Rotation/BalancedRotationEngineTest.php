<?php

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\PairHistory;
use App\Domain\Rotation\Weights;

function weights(float $star = 3, float $partner = 4, float $opponent = 1.5, float $skipped = 2, int $window = 8): Weights
{
    return new Weights($star, $partner, $opponent, $skipped, $window);
}

function engine(?Weights $weights = null): BalancedRotationEngine
{
    return new BalancedRotationEngine($weights ?? weights());
}

function cand(int $id, int $stars = 3, int $games = 0, int $queuedAt = 0): Candidate
{
    return new Candidate($id, $stars, $games, $queuedAt);
}

/** @return array<int> */
function pickedIds(?object $result): array
{
    $ids = [...$result->teamA, ...$result->teamB];
    sort($ids);

    return $ids;
}

it('returns null with fewer than 4 candidates', function (int $count) {
    $candidates = array_map(fn (int $i) => cand($i), $count === 0 ? [] : range(1, $count));

    expect(engine()->pickMatch($candidates, new PairHistory))->toBeNull();
})->with([0, 1, 2, 3]);

it('picks exactly four candidates when there are only four', function () {
    $result = engine()->pickMatch([cand(1), cand(2), cand(3), cand(4)], new PairHistory);

    expect(pickedIds($result))->toBe([1, 2, 3, 4])
        ->and($result->cost)->toBe(0.0);
});

it('prioritises fewest effective games', function () {
    $result = engine()->pickMatch([
        cand(1, games: 2), cand(2, games: 0), cand(3, games: 0), cand(4, games: 1), cand(5, games: 0),
    ], new PairHistory);

    expect(pickedIds($result))->toBe([2, 3, 4, 5]);
});

it('breaks games ties by longest wait, then by id', function () {
    $byWait = engine()->pickMatch([
        cand(1, queuedAt: 500), cand(2, queuedAt: 100), cand(3, queuedAt: 200), cand(4, queuedAt: 300), cand(5, queuedAt: 400),
    ], new PairHistory);
    $byId = engine()->pickMatch([cand(9), cand(8), cand(7), cand(6), cand(5)], new PairHistory);

    expect(pickedIds($byWait))->toBe([2, 3, 4, 5])
        ->and(pickedIds($byId))->toBe([5, 6, 7, 8]);
});

it('picks the most even star split', function () {
    $result = engine()->pickMatch([cand(1, 6), cand(2, 6), cand(3, 1), cand(4, 1)], new PairHistory);

    expect($result->teamA)->toBe([1, 3])
        ->and($result->teamB)->toBe([2, 4])
        ->and($result->breakdown['star_balance'])->toBe(0.0);
});

it('avoids repeat partners', function () {
    $history = PairHistory::fromMatches([[[1, 2], [5, 6]], [[1, 3], [5, 6]]]);

    $result = engine()->pickMatch([cand(1), cand(2), cand(3), cand(4)], $history);

    expect($result->teamA)->toBe([1, 4])
        ->and($result->teamB)->toBe([2, 3])
        ->and($result->breakdown['repeat_partner'])->toBe(0.0);
});

it('avoids repeat opponents', function () {
    $history = PairHistory::fromMatches([[[1, 8], [3, 9]], [[1, 8], [3, 9]]]);

    $result = engine()->pickMatch([cand(1), cand(2), cand(3), cand(4)], $history);

    expect($result->teamA)->toBe([1, 3])
        ->and($result->teamB)->toBe([2, 4])
        ->and($result->breakdown['repeat_opponent'])->toBe(0.0);
});

it('weighs a repeat partner above a repeat opponent by default', function () {
    expect(weights()->repeatPartner)->toBeGreaterThan(weights()->repeatOpponent);
});

it('sacrifices queue order for a better balance when the gain is bigger than the skip', function () {
    // Front four are 3,3,3,4 (best imbalance 1 star = 3). Swapping in the 5th (3 stars) costs 1 rank = 2.
    $result = engine()->pickMatch([cand(1, 3), cand(2, 3), cand(3, 3), cand(4, 4, queuedAt: 1), cand(5, 3, queuedAt: 2)], new PairHistory);

    expect(pickedIds($result))->toBe([1, 2, 3, 5]);
});

it('does not starve the front of the queue for a marginal gain', function () {
    // Front player is 2 stars among 1s: imbalance 1 star = 3; skipping them costs 4 ranks = 8.
    $candidates = [cand(1, 2), cand(2, 1, queuedAt: 1), cand(3, 1, queuedAt: 2), cand(4, 1, queuedAt: 3), cand(5, 1, queuedAt: 4)];

    expect(pickedIds(engine()->pickMatch($candidates, new PairHistory)))->toBe([1, 2, 3, 4]);
});

it('skips a front player only when imbalance outweighs the skipped ranks', function () {
    // A 6-star at the front with 1-star others costs 5 stars of imbalance (15) vs 8 to skip them.
    $candidates = [cand(1, 6), cand(2, 1, queuedAt: 1), cand(3, 1, queuedAt: 2), cand(4, 1, queuedAt: 3), cand(5, 1, queuedAt: 4)];

    expect(pickedIds(engine()->pickMatch($candidates, new PairHistory)))->toBe([2, 3, 4, 5]);
    // With a heavy skip weight the front player is always kept.
    expect(pickedIds(engine(weights(skipped: 10))->pickMatch($candidates, new PairHistory)))->toBe([1, 2, 3, 4]);
});

it('ties on cost go to the lowest skipped priority, not the lowest ids', function () {
    // Front of the queue has the highest ids, so id order and priority order disagree.
    $candidates = [];
    foreach ([60, 50, 40, 30, 20, 10] as $rank => $id) {
        $candidates[] = cand($id, queuedAt: $rank);
    }

    $result = engine(weights(star: 0, partner: 0, opponent: 0, skipped: 0))->pickMatch($candidates, new PairHistory);

    expect(pickedIds($result))->toBe([30, 40, 50, 60]);
});

it('ties on cost and skipped priority go to the smallest sorted id list', function () {
    // Stars by rank 1,1,1,2,2,1: only ranks {0,1,2,5} and {0,1,3,4} balance, both with skipped 2.
    // The group enumerated first has the larger ids, so only the id rule picks the other.
    $stars = [1, 1, 1, 2, 2, 1];
    $ids = [1, 2, 9, 3, 4, 8];
    $candidates = [];
    foreach ($ids as $rank => $id) {
        $candidates[] = cand($id, $stars[$rank], queuedAt: $rank);
    }

    $result = engine(weights(star: 100, partner: 0, opponent: 0, skipped: 1))->pickMatch($candidates, new PairHistory);

    expect(pickedIds($result))->toBe([1, 2, 3, 4])
        ->and($result->cost)->toBe(2.0);
});

it('ties between splits of the same group go to the first split', function () {
    // Stars 1,1,2,2: splits {1,3|2,4} and {1,4|2,3} both balance; the first enumerated wins.
    $result = engine()->pickMatch([cand(1, 1), cand(2, 1), cand(3, 2), cand(4, 2)], new PairHistory);

    expect($result->teamA)->toBe([1, 3])
        ->and($result->teamB)->toBe([2, 4]);
});

it('only looks inside the window', function () {
    // Every pair among ids 1-8 has partnered 5 times; id 9 (rank 8) is fresh.
    $matches = [];
    for ($a = 1; $a <= 8; $a++) {
        for ($b = $a + 1; $b <= 8; $b++) {
            for ($n = 0; $n < 5; $n++) {
                $matches[] = [[$a, $b], [90, 91]];
            }
        }
    }
    $history = PairHistory::fromMatches($matches);
    $candidates = array_map(fn (int $i) => cand($i, queuedAt: $i), range(1, 9));

    $inside = engine(weights(window: 8))->pickMatch($candidates, $history);
    $wide = engine(weights(window: 9))->pickMatch($candidates, $history);

    expect(pickedIds($inside))->toBe([1, 2, 3, 4])
        ->and(pickedIds($wide))->toBe([1, 2, 3, 9]);
});

it('with window 4 always picks the top four', function () {
    $candidates = [cand(1, 6), cand(2, 6, queuedAt: 1), cand(3, 6, queuedAt: 2), cand(4, 6, queuedAt: 3), cand(5, 1, queuedAt: 4), cand(6, 1, queuedAt: 5)];

    expect(pickedIds(engine(weights(window: 4))->pickMatch($candidates, new PairHistory)))->toBe([1, 2, 3, 4]);
});

it('rejects a window below 4', function () {
    weights(window: 3);
})->throws(InvalidArgumentException::class);

it('handles odd counts and more candidates than the window', function (int $count) {
    $candidates = array_map(fn (int $i) => cand($i, queuedAt: $i), range(1, $count));

    $result = engine()->pickMatch($candidates, new PairHistory);

    expect(pickedIds($result))->toBe([1, 2, 3, 4]);
})->with([5, 7, 8, 12, 30]);

it('is deterministic for identical input regardless of input order', function () {
    $candidates = [cand(4, 2), cand(1, 5), cand(7, 1), cand(3, 4), cand(2, 3), cand(6, 6), cand(5, 2)];
    $history = PairHistory::fromMatches([[[1, 2], [3, 4]], [[1, 3], [2, 5]]]);

    $first = engine()->pickMatch($candidates, $history);
    $second = engine()->pickMatch($candidates, $history);
    $reversed = engine()->pickMatch(array_reverse($candidates), $history);

    expect($second)->toEqual($first)
        ->and($reversed)->toEqual($first);
});

it('reports a breakdown that sums to the total cost', function () {
    $history = PairHistory::fromMatches([[[1, 2], [3, 4]]]);

    $result = engine()->pickMatch([cand(1, 5), cand(2, 2), cand(3, 4), cand(4, 3), cand(5, 3)], $history);

    expect(array_sum($result->breakdown))->toEqualWithDelta($result->cost, 1e-9);
});

it('builds weights from a config array with defaults for missing keys', function () {
    $w = Weights::fromConfig(['star_balance' => 5, 'window' => 2]);

    expect($w->starBalance)->toBe(5.0)
        ->and($w->repeatPartner)->toBe(4.0)
        ->and($w->window)->toBe(4);
});

it('counts pair history from matches', function () {
    $history = PairHistory::fromMatches([[[1, 2], [3, 4]], [[2, 1], [4, 5]]]);

    expect($history->partnerCount(1, 2))->toBe(2)
        ->and($history->partnerCount(2, 1))->toBe(2)
        ->and($history->opponentCount(1, 3))->toBe(1)
        ->and($history->opponentCount(4, 1))->toBe(2)
        ->and($history->partnerCount(1, 3))->toBe(0);
});

describe('pickReplacement', function () {
    it('returns null with no candidates', function () {
        expect(engine()->pickReplacement([cand(1)], [cand(2), cand(3)], [], new PairHistory))->toBeNull();
    });

    it('rejects an invalid team shape', function () {
        engine()->pickReplacement([cand(1), cand(2)], [cand(3), cand(4)], [cand(5)], new PairHistory);
    })->throws(InvalidArgumentException::class);

    it('fills a slot on team A to balance stars', function () {
        $result = engine()->pickReplacement(
            [cand(1, 5)],
            [cand(2, 3), cand(3, 3)],
            [cand(10, 3), cand(11, 1, queuedAt: 1), cand(12, 6, queuedAt: 2)],
            new PairHistory,
        );

        expect($result->playerId)->toBe(11)
            ->and($result->teamA)->toBe([1, 11])
            ->and($result->teamB)->toBe([2, 3]);
    });

    it('fills a slot on team B', function () {
        $result = engine()->pickReplacement(
            [cand(1, 5), cand(2, 1)],
            [cand(3, 2)],
            [cand(10, 6), cand(11, 4, queuedAt: 1)],
            new PairHistory,
        );

        // Team A = 6; team B needs 4: id 11 gives 6 (balanced) at rank 1 = 2, id 10 gives 8 (diff 2 = 6).
        expect($result->playerId)->toBe(11)
            ->and($result->teamB)->toBe([3, 11]);
    });

    it('prefers the highest priority candidate when costs are equal', function () {
        $result = engine()->pickReplacement(
            [cand(1)],
            [cand(2), cand(3)],
            [cand(20, queuedAt: 5), cand(10, queuedAt: 9), cand(30, queuedAt: 7)],
            new PairHistory,
        );

        expect($result->playerId)->toBe(20);
    });

    it('avoids a repeat partner', function () {
        $history = PairHistory::fromMatches([[[1, 10], [8, 9]], [[1, 10], [8, 9]]]);

        $result = engine()->pickReplacement(
            [cand(1)],
            [cand(2), cand(3)],
            [cand(10), cand(11, queuedAt: 1)],
            $history,
        );

        expect($result->playerId)->toBe(11);
    });

    it('avoids a repeat opponent', function () {
        $history = PairHistory::fromMatches([[[2, 8], [10, 9]], [[3, 8], [10, 9]]]);

        $result = engine()->pickReplacement(
            [cand(1)],
            [cand(2), cand(3)],
            [cand(10), cand(11, queuedAt: 1)],
            $history,
        );

        expect($result->playerId)->toBe(11);
    });

    it('prefers a better fit when its gain beats the rank it skips', function () {
        $result = engine()->pickReplacement(
            [cand(1, 3)],
            [cand(2, 3), cand(3, 3)],
            [cand(10, 4, games: 0), cand(11, 3, games: 1)],
            new PairHistory,
        );

        // 10 costs 1 star of imbalance (3); 11 costs rank 1 (2), so 11 wins.
        expect($result->playerId)->toBe(11);
    });

    it('keeps the front candidate when another is only a slightly better fit', function () {
        $result = engine(weights(star: 1))->pickReplacement(
            [cand(1, 3)],
            [cand(2, 3), cand(3, 3)],
            [cand(10, 4, games: 0), cand(11, 3, games: 1)],
            new PairHistory,
        );

        // 10 costs 1 star (1); 11 costs rank 1 (2), so the long-waiting 10 wins.
        expect($result->playerId)->toBe(10);
    });
});
