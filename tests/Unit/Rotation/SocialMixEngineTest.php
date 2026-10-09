<?php

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\PairHistory;
use App\Domain\Rotation\Weights;

function socialEngine(): BalancedRotationEngine
{
    return new BalancedRotationEngine(Weights::socialFromConfig([]));
}

/** @return list<Candidate> ids 1..$count in queue order, with the given stars per id. */
function socialQueue(int $count, array $stars = []): array
{
    return array_map(fn (int $i) => new Candidate($i, $stars[$i] ?? 3, 0, $i), range(1, $count));
}

/** Partner history: each given id has partnered each of 1, 2, 3 and each other once. */
function partneredWithTopThree(array $ids): PairHistory
{
    $matches = [];
    foreach ($ids as $n => $id) {
        foreach ([1, 2, 3] as $m => $other) {
            $matches[] = [[$id, $other], [100 + $n * 10 + $m, 200 + $n * 10 + $m]];
        }
    }

    // The repeaters have also partnered each other, so they cannot form a fresh pair among themselves.
    foreach ($ids as $n => $id) {
        foreach (array_slice($ids, $n + 1) as $m => $other) {
            $matches[] = [[$id, $other], [300 + $n * 10 + $m, 400 + $n * 10 + $m]];
        }
    }

    return PairHistory::fromMatches($matches);
}

it('avoids a repeated partner when splitting a fixed four', function () {
    $history = PairHistory::fromMatches([[[1, 2], [5, 6]]]);

    $result = socialEngine()->pickMatch(socialQueue(4), $history);

    expect($result->breakdown['repeat_partner'])->toBe(0.0)
        ->and($result->teamA)->not->toBe([1, 2])
        ->and($result->teamB)->not->toBe([1, 2]);
});

it('prefers a fresh pairing that skips up to 2 queue ranks', function () {
    // 4 and 5 have partnered each of 1, 2, 3, so any group with them repeats a partner. 6 is fresh (2 ranks skipped).
    $result = socialEngine()->pickMatch(socialQueue(6), partneredWithTopThree([4, 5]));

    expect(socialIds($result))->toBe([1, 2, 3, 6])
        ->and($result->breakdown['repeat_partner'])->toBe(0.0);
});

it('ties at 3 skipped ranks and keeps the queue order', function () {
    // 4, 5, 6 are repeaters, so the first fresh player (7) costs 3 ranks = one repeat partner.
    $result = socialEngine()->pickMatch(socialQueue(7), partneredWithTopThree([4, 5, 6]));

    expect(socialIds($result))->toBe([1, 2, 3, 4]);
});

it('never lets stars change the pick', function () {
    $history = PairHistory::fromMatches([[[1, 2], [5, 6]], [[3, 4], [7, 8]], [[1, 3], [2, 4]]]);
    $base = socialEngine()->pickMatch(socialQueue(8), $history);

    foreach ([[1, 1, 1, 1, 6, 6, 6, 6], [6, 1, 6, 1, 6, 1, 6, 1], [1, 6, 6, 6, 1, 1, 1, 6]] as $stars) {
        $result = socialEngine()->pickMatch(socialQueue(8, array_combine(range(1, 8), $stars)), $history);

        expect($result->teamA)->toBe($base->teamA)
            ->and($result->teamB)->toBe($base->teamB)
            ->and($result->breakdown['star_balance'])->toBe(0.0);
    }
});

it('picks the top four by priority when there is no history', function () {
    $candidates = [
        new Candidate(1, 6, 2, 0), new Candidate(2, 1, 0, 1), new Candidate(3, 6, 0, 2),
        new Candidate(4, 1, 1, 3), new Candidate(5, 6, 0, 4),
    ];

    expect(socialIds(socialEngine()->pickMatch($candidates, new PairHistory)))->toBe([2, 3, 4, 5]);
});

it('spreads opponents among fresh-partner options', function () {
    // 1 and 2 have already faced each other twice, so they should not face each other again.
    $history = PairHistory::fromMatches([[[1, 9], [2, 8]], [[1, 9], [2, 8]]]);

    $result = socialEngine()->pickMatch(socialQueue(4), $history);

    expect($result->teamA)->toBe([1, 2])
        ->and($result->teamB)->toBe([3, 4])
        ->and($result->breakdown['repeat_opponent'])->toBe(0.0);
});

it('builds social weights from config with defaults and overrides', function () {
    $defaults = Weights::socialFromConfig([]);
    expect($defaults->starBalance)->toBe(0.0)
        ->and($defaults->repeatPartner)->toBe(6.0)
        ->and($defaults->repeatOpponent)->toBe(2.0)
        ->and($defaults->skippedPriority)->toBe(2.0)
        ->and($defaults->window)->toBe(8);

    $custom = Weights::socialFromConfig([
        'star_balance' => 9,
        'window' => 6,
        'social' => ['repeat_partner' => 10, 'repeat_opponent' => 3, 'skipped_priority' => 1],
    ]);
    expect($custom->starBalance)->toBe(0.0)
        ->and($custom->repeatPartner)->toBe(10.0)
        ->and($custom->repeatOpponent)->toBe(3.0)
        ->and($custom->skippedPriority)->toBe(1.0)
        ->and($custom->window)->toBe(6);
});

/** @return list<int> */
function socialIds(object $result): array
{
    $ids = [...$result->teamA, ...$result->teamB];
    sort($ids);

    return $ids;
}

it('puts fewer repeat partners ahead of any amount of opponent repeats within a group', function () {
    // 1+2 partnered once; 1 v 2 and 3 v 4 have each faced off twice. 12|34 costs 6 on partners
    // alone; both fresh splits cost 8 on opponents, yet partners come first so one of them wins.
    $history = PairHistory::fromMatches([
        [[1, 9], [2, 8]], [[1, 9], [2, 8]], [[3, 9], [4, 8]], [[3, 9], [4, 8]], [[1, 2], [6, 7]],
    ]);

    $result = socialEngine()->pickMatch(socialQueue(4), $history);

    expect($result->breakdown['repeat_partner'])->toBe(0.0)
        ->and($result->teamA)->not->toBe([1, 2]);
    // The weighted cost alone would have kept 1+2 together (6 versus 8).
    $weighted = new BalancedRotationEngine(new Weights(0, 6, 2, 2, 8));
    expect($weighted->pickMatch(socialQueue(4), $history)->teamA)->toBe([1, 2]);
});

it('chooses the split with two repeat opponents over one repeat partner', function () {
    // 12|34 repeats a partner (6). Both fresh splits repeat 1 v 2 and 3 v 4 once each (4).
    $history = PairHistory::fromMatches([[[1, 2], [6, 7]], [[1, 9], [2, 8]], [[3, 9], [4, 8]]]);

    $result = socialEngine()->pickMatch(socialQueue(4), $history);

    expect($result->breakdown['repeat_partner'])->toBe(0.0)
        ->and($result->breakdown['repeat_opponent'])->toBe(4.0);
});

it('tells social apart from balanced when stars differ', function () {
    $stars = [1 => 6, 2 => 6, 3 => 1, 4 => 1];
    $history = PairHistory::fromMatches([[[1, 3], [7, 8]], [[1, 4], [7, 8]]]);

    $social = socialEngine()->pickMatch(socialQueue(4, $stars), $history);
    $balanced = (new BalancedRotationEngine(Weights::fromConfig([])))->pickMatch(socialQueue(4, $stars), $history);

    // Both star-even splits (6+1 v 6+1) repeat a partner. Balanced still takes one; social ignores
    // stars and pairs the two 6s and the two 1s, which is fresh.
    expect($balanced->breakdown['repeat_partner'])->toBe(4.0)
        ->and($social->breakdown['repeat_partner'])->toBe(0.0)
        ->and($social->teamA)->toBe([1, 2]);
});

it('replaces with a fresh partner within 2 ranks over a repeat partner at rank 0', function () {
    $candidates = [new Candidate(3, 3, 0, 1), new Candidate(4, 3, 0, 2), new Candidate(5, 3, 0, 3)];
    // The open slot partners 1, and 3 (rank 0) has partnered 1 before. 4 (rank 1) is fresh.
    $history = PairHistory::fromMatches([[[1, 3], [8, 9]]]);

    $result = socialEngine()->pickReplacement([new Candidate(1, 3, 0, 0)], [new Candidate(6, 3, 0, 0), new Candidate(7, 3, 0, 0)], $candidates, $history);

    expect($result->playerId)->toBe(4);
});

/**
 * Plays $rounds rounds with $courts courts: each round picks one match per court from the
 * waiting players, every picked player gets +1 game and goes to the back of the queue.
 * Returns the worst games spread seen after any round (and the round it first hit it).
 *
 * @return array{spread: int, round: int, games: array<int, int>, matches: list<array{0: list<int>, 1: list<int>}>}
 */
function simulateSocial(int $pool, int $courts, int $rounds): array
{
    $engine = socialEngine();
    $games = array_fill(1, $pool, 0);
    $queuedAt = array_combine(range(1, $pool), range(1, $pool));
    $matches = [];
    $clock = $pool;
    $worst = ['spread' => 0, 'round' => 0, 'games' => $games, 'matches' => []];

    for ($round = 1; $round <= $rounds; $round++) {
        $taken = [];
        $picked = [];
        for ($court = 0; $court < $courts; $court++) {
            $candidates = [];
            foreach ($games as $id => $played) {
                if (! isset($taken[$id])) {
                    $candidates[] = new Candidate($id, 3, $played, $queuedAt[$id]);
                }
            }
            $result = $engine->pickMatch($candidates, PairHistory::fromMatches($matches));
            $matches[] = [$result->teamA, $result->teamB];
            foreach ([...$result->teamA, ...$result->teamB] as $id) {
                $taken[$id] = true;
                $picked[] = $id;
            }
        }
        foreach ($picked as $id) {
            $games[$id]++;
            $queuedAt[$id] = ++$clock;
        }

        $spread = max($games) - min($games);
        if ($spread > $worst['spread']) {
            $worst = ['spread' => $spread, 'round' => $round, 'games' => $games, 'matches' => []];
        }
    }
    $worst['matches'] = $matches;

    return $worst;
}

// Settled with the user at the P7.4g review: partners come strictly first, and a games gap of
// up to 2 is accepted for pools of 6 or more (no guard, no retune). Pools of 4 and 5 stay within 1.
it('keeps the games spread bounded over 60 simulated matches on one court', function (int $pool) {
    $run = simulateSocial($pool, 1, 60);

    expect($run['spread'])->toBeLessThanOrEqual($pool <= 5 ? 1 : 2, "pool {$pool} reached a spread of {$run['spread']} at match {$run['round']}: ".json_encode($run['games']));
})->with(range(4, 12));

it('keeps the games spread bounded over 60 simulated matches on two courts', function (int $pool) {
    $run = simulateSocial($pool, 2, 30);

    expect($run['spread'])->toBeLessThanOrEqual($pool <= 5 ? 1 : 2, "pool {$pool} (2 courts) reached a spread of {$run['spread']} at round {$run['round']}: ".json_encode($run['games']));
})->with([8, 9, 10, 12]);

it('spreads partners evenly in small pools', function (int $pool) {
    $matches = simulateSocial($pool, 1, 30)['matches'];
    $history = PairHistory::fromMatches($matches);
    $counts = [];
    foreach (range(1, $pool - 1) as $a) {
        foreach (range($a + 1, $pool) as $b) {
            $counts[] = $history->partnerCount($a, $b);
        }
    }

    expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
})->with([4, 5]);
