<?php

use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\MixedRotationEngine;
use App\Domain\Rotation\PairHistory;
use App\Domain\Rotation\Weights;
use App\Enums\Gender;

function mixedEngine(?Weights $weights = null): MixedRotationEngine
{
    return new MixedRotationEngine($weights ?? new Weights(3, 4, 1.5, 2, 8));
}

function mc(int $id, Gender $gender, int $stars = 3, int $games = 0, int $queuedAt = 0): Candidate
{
    return new Candidate($id, $stars, $games, $queuedAt, $gender);
}

/** Men have ids 1..n, women 101..: both lists in priority order. */
function mixedPool(int $men, int $women, int $stars = 3): array
{
    $pool = [];
    for ($i = 1; $i <= $men; $i++) {
        $pool[] = mc($i, Gender::Man, $stars, 0, $i);
    }
    for ($i = 1; $i <= $women; $i++) {
        $pool[] = mc(100 + $i, Gender::Woman, $stars, 0, $i);
    }

    return $pool;
}

it('puts one man and one woman on every team', function () {
    $result = mixedEngine()->pickMatch(mixedPool(5, 5), new PairHistory);

    foreach ([$result->teamA, $result->teamB] as $team) {
        expect(count(array_filter($team, fn (int $id) => $id < 100)))->toBe(1)
            ->and(count(array_filter($team, fn (int $id) => $id > 100)))->toBe(1);
    }
});

it('returns null unless there are two men and two women', function (int $men, int $women) {
    expect(mixedEngine()->pickMatch(mixedPool($men, $women), new PairHistory))->toBeNull();
})->with([[0, 6], [1, 6], [6, 1], [6, 0], [3, 1], [1, 3]]);

it('never picks players with no gender', function () {
    $pool = [...mixedPool(1, 2), new Candidate(50, 3, 0, 0), new Candidate(51, 3, 0, 0)];

    expect(mixedEngine()->pickMatch($pool, new PairHistory))->toBeNull();

    $result = mixedEngine()->pickMatch([...$pool, mc(2, Gender::Man)], new PairHistory);
    expect([...$result->teamA, ...$result->teamB])->not->toContain(50)->not->toContain(51);
});

it('balances stars between the teams', function () {
    // Men 5 and 1, women 5 and 1: the balanced split pairs strong with weak on each team.
    $pool = [mc(1, Gender::Man, 5), mc(2, Gender::Man, 1), mc(101, Gender::Woman, 5), mc(102, Gender::Woman, 1)];
    $result = mixedEngine()->pickMatch($pool, new PairHistory);

    expect($result->breakdown['star_balance'])->toBe(0.0);
    $stars = fn (array $team) => array_sum(array_map(fn (int $id) => $pool[array_search($id, array_map(fn ($c) => $c->id, $pool))]->stars, $team));
    expect($stars($result->teamA))->toBe($stars($result->teamB));
});

it('avoids repeating a partner', function () {
    $pool = mixedPool(2, 2);
    // 1 already partnered 101 and 2 partnered 102: the other pairing is chosen.
    $history = PairHistory::fromMatches([[[1, 101], [2, 102]]]);
    $result = mixedEngine()->pickMatch($pool, $history);

    $teams = [$result->teamA, $result->teamB];
    $has = fn (int $a, int $b) => collect($teams)->contains(fn (array $t) => in_array($a, $t) && in_array($b, $t));
    expect($has(1, 102))->toBeTrue()->and($has(2, 101))->toBeTrue();
});

it('keeps priority fairness within each gender', function () {
    // Women 101..104 have played 0 games, so the top two women must be picked even though a man has played more.
    $pool = [
        mc(1, Gender::Man, 3, 0, 1), mc(2, Gender::Man, 3, 0, 2), mc(3, Gender::Man, 3, 5, 3),
        mc(101, Gender::Woman, 3, 3, 1), mc(102, Gender::Woman, 3, 0, 2), mc(103, Gender::Woman, 3, 0, 3),
    ];
    $result = mixedEngine()->pickMatch($pool, new PairHistory);
    $ids = [...$result->teamA, ...$result->teamB];
    sort($ids);

    expect($ids)->toBe([1, 2, 102, 103]);
});

it('does not charge skipped priority for skipping a gender', function () {
    // Four men rank ahead of every woman overall. The top 2 men + top 2 women is the baseline, so cost is zero skipped.
    $pool = [];
    foreach ([1, 2, 3, 4] as $i) {
        $pool[] = mc($i, Gender::Man, 3, 0, $i);
    }
    $pool[] = mc(101, Gender::Woman, 3, 5, 1);
    $pool[] = mc(102, Gender::Woman, 3, 5, 2);

    $result = mixedEngine()->pickMatch($pool, new PairHistory);

    expect($result->breakdown['skipped_priority'])->toBe(0.0);
});

it('breaks exact ties deterministically', function () {
    $pool = mixedPool(4, 4);
    $first = mixedEngine()->pickMatch($pool, new PairHistory);
    $second = mixedEngine()->pickMatch(array_reverse($pool), new PairHistory);

    expect($second->teamA)->toBe($first->teamA)->and($second->teamB)->toBe($first->teamB);
});

it('replaces with the opposite gender of the player left on the open team', function () {
    $stays = mc(1, Gender::Man);
    $partnerless = mc(101, Gender::Woman);
    $other = mc(2, Gender::Man);
    // Open slot on team A next to man 1: only women are eligible, even though men rank higher.
    $candidates = [mc(3, Gender::Man, 3, 0, 1), mc(4, Gender::Man, 3, 0, 2), mc(102, Gender::Woman, 3, 4, 3), mc(103, Gender::Woman, 3, 4, 4)];

    $result = mixedEngine()->pickReplacement([$stays], [$other, $partnerless], $candidates, new PairHistory);
    expect($result->playerId)->toBeIn([102, 103])->and($result->teamA)->toHaveCount(2);

    // Open slot on team B next to a woman: only men are eligible.
    $result = mixedEngine()->pickReplacement([$stays, $partnerless], [mc(104, Gender::Woman)], $candidates, new PairHistory);
    expect($result->playerId)->toBeIn([3, 4])->and($result->teamB)->toHaveCount(2);
});

it('charges skipped priority by rank within each gender', function () {
    // Picking the 3rd man (skipping man 2) costs exactly one skipped rank, as in balanced mode.
    $pool = [
        mc(1, Gender::Man, 5, 0, 1), mc(2, Gender::Man, 5, 0, 2), mc(3, Gender::Man, 1, 0, 3),
        mc(101, Gender::Woman, 5, 0, 1), mc(102, Gender::Woman, 1, 0, 2),
    ];
    $result = mixedEngine()->pickMatch($pool, new PairHistory);
    $ids = [...$result->teamA, ...$result->teamB];
    sort($ids);

    expect($ids)->toBe([1, 3, 101, 102])
        ->and($result->breakdown['skipped_priority'])->toBe(2.0);
});

it('returns null as a replacement when the remaining teammate has no gender', function () {
    $result = mixedEngine()->pickReplacement(
        [new Candidate(1, 3, 0, 0)],
        [mc(2, Gender::Man), mc(101, Gender::Woman)],
        [mc(3, Gender::Man), mc(102, Gender::Woman)],
        new PairHistory,
    );

    expect($result)->toBeNull();
});

it('returns null as a replacement when no one of the needed gender is waiting', function () {
    $result = mixedEngine()->pickReplacement(
        [mc(1, Gender::Man)],
        [mc(2, Gender::Man), mc(101, Gender::Woman)],
        [mc(3, Gender::Man), new Candidate(9, 3, 0, 0)],
        new PairHistory,
    );

    expect($result)->toBeNull();
});
