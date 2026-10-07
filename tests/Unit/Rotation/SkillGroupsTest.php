<?php

use App\Domain\Rotation\SkillGroups;

function sgRow(int $from, int $to, int $min, int $max): array
{
    return ['from_court' => $from, 'to_court' => $to, 'min_stars' => $min, 'max_stars' => $max];
}

test('the default groups split the courts in two, the top half taking 4 to 6 stars', function (int $courts, int $topTo) {
    $groups = SkillGroups::defaultFor($courts);

    expect($groups->toArray())->toBe([sgRow(1, $topTo, 4, 6), sgRow($topTo + 1, $courts, 1, 3)])
        ->and($groups->count())->toBe(2);
})->with([[2, 1], [3, 2], [4, 2], [5, 3], [8, 4]]);

test('skill courts need at least 2 courts', function () {
    expect(fn () => SkillGroups::defaultFor(1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SkillGroups::fromArray([sgRow(1, 1, 4, 6), sgRow(2, 2, 1, 3)], 1))->toThrow(InvalidArgumentException::class);
});

test('it answers group, court range and label lookups', function () {
    $groups = SkillGroups::fromArray([sgRow(1, 2, 5, 6), sgRow(3, 3, 3, 4), sgRow(4, 5, 1, 2)], 5);

    expect($groups->groupForStars(6))->toBe(1)
        ->and($groups->groupForStars(5))->toBe(1)
        ->and($groups->groupForStars(4))->toBe(2)
        ->and($groups->groupForStars(3))->toBe(2)
        ->and($groups->groupForStars(2))->toBe(3)
        ->and($groups->groupForStars(1))->toBe(3)
        ->and($groups->courtRange(1))->toBe([1, 2])
        ->and($groups->courtRange(2))->toBe([3])
        ->and($groups->courtRange(3))->toBe([4, 5])
        ->and($groups->groupForCourt(1))->toBe(1)
        ->and($groups->groupForCourt(3))->toBe(2)
        ->and($groups->groupForCourt(5))->toBe(3)
        ->and($groups->groupForCourt(6))->toBeNull()
        ->and($groups->groupForCourt(0))->toBeNull()
        ->and($groups->label(1))->toBe('Courts 1–2')
        ->and($groups->label(2))->toBe('Court 3')
        ->and($groups->starRange(3))->toBe(['min' => 1, 'max' => 2]);
});

test('stars outside 1 to 6 have no group', function () {
    $groups = SkillGroups::defaultFor(4);

    expect($groups->tryGroupForStars(0))->toBeNull()
        ->and($groups->tryGroupForStars(7))->toBeNull()
        ->and(fn () => $groups->groupForStars(7))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $groups->courtRange(3))->toThrow(InvalidArgumentException::class);
});

test('groups are put in court order and numeric strings are accepted', function () {
    $groups = SkillGroups::fromArray([
        ['from_court' => '3', 'to_court' => '4', 'min_stars' => '1', 'max_stars' => '3'],
        sgRow(1, 2, 4, 6),
    ], 4);

    expect($groups->toArray())->toBe([sgRow(1, 2, 4, 6), sgRow(3, 4, 1, 3)]);
});

test('invalid groups are rejected', function (array $raw, int $courts) {
    expect(fn () => SkillGroups::fromArray($raw, $courts))->toThrow(InvalidArgumentException::class)
        ->and(SkillGroups::tryFromArray($raw, $courts))->toBeNull();
})->with([
    'a single group' => [[sgRow(1, 4, 1, 6)], 4],
    'no groups' => [[], 4],
    'a group with no courts' => [[sgRow(1, 2, 4, 6), sgRow(3, 2, 1, 3), sgRow(3, 4, 1, 3)], 4],
    'a gap in the courts' => [[sgRow(1, 1, 4, 6), sgRow(3, 4, 1, 3)], 4],
    'overlapping courts' => [[sgRow(1, 3, 4, 6), sgRow(3, 4, 1, 3)], 4],
    'courts not reaching the last court' => [[sgRow(1, 2, 4, 6), sgRow(3, 3, 1, 3)], 4],
    'courts beyond the last court' => [[sgRow(1, 2, 4, 6), sgRow(3, 5, 1, 3)], 4],
    'not starting at court 1' => [[sgRow(2, 2, 4, 6), sgRow(3, 4, 1, 3)], 4],
    'overlapping stars' => [[sgRow(1, 2, 3, 6), sgRow(3, 4, 1, 3)], 4],
    'a star gap' => [[sgRow(1, 2, 5, 6), sgRow(3, 4, 1, 3)], 4],
    'stars not reaching 6' => [[sgRow(1, 2, 4, 5), sgRow(3, 4, 1, 3)], 4],
    'stars not starting at 1' => [[sgRow(1, 2, 4, 6), sgRow(3, 4, 2, 3)], 4],
    'stars beyond 6' => [[sgRow(1, 2, 4, 7), sgRow(3, 4, 1, 3)], 4],
    'min above max' => [[sgRow(1, 2, 6, 4), sgRow(3, 4, 1, 3)], 4],
    'a missing key' => [[sgRow(1, 2, 4, 6), ['from_court' => 3, 'to_court' => 4, 'min_stars' => 1]], 4],
    'a non numeric value' => [[sgRow(1, 2, 4, 6), ['from_court' => 3, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 'x']], 4],
    'a decimal value' => [[sgRow(1, 2, 4, 6), ['from_court' => 3, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 3.5]], 4],
]);

test('resizing moves only the last group and never empties one', function () {
    $groups = SkillGroups::defaultFor(4);

    expect($groups->resizedTo(6)->toArray())->toBe([sgRow(1, 2, 4, 6), sgRow(3, 6, 1, 3)])
        ->and($groups->resizedTo(3)->toArray())->toBe([sgRow(1, 2, 4, 6), sgRow(3, 3, 1, 3)])
        ->and(fn () => $groups->resizedTo(2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $groups->resizedTo(1))->toThrow(InvalidArgumentException::class);
});
