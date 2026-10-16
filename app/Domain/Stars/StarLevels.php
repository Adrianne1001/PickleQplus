<?php

namespace App\Domain\Stars;

use App\Models\Club;

/**
 * The six star levels with a short label, a plain-language hint and the DUPR range worked out
 * from the club's star bands (never hard-coded). The logic is pure PHP and has no wording of its
 * own for the range: App\Support\StarLevelText builds the text. forClub() is only a thin wrapper
 * that reads the club's bands.
 *
 * Shape of every row:
 *  - `stars` (1 to 6), `label`, `hint` (English source text, translate with __()).
 *  - `min`: inclusive lower bound, null for 1 star (no lower bound).
 *  - `max`: upper bound, null for 6 stars (no upper bound).
 *  - `max_inclusive`: true when `max` is the last rating of the level on the 0.01 grid (the next
 *    band's threshold minus 0.01, e.g. 2.99); false when `max` is the next threshold itself and the
 *    rating must stay below it (1 star always, and any band that is not on the 0.01 grid).
 *  - `decimals`: how many decimals show `min` and `max` exactly (2 for the usual bands).
 * With the default bands that reads as below 2.50, 2.50 to 2.99, 3.00 to 3.49, 3.50 to 3.99,
 * 4.00 to 4.49 and 4.50 and up. The ranges always agree with StarRating::fromRating.
 *
 * @phpstan-type StarLevel array{stars: int, label: string, hint: string, min: float|null, max: float|null, max_inclusive: bool, decimals: int}
 */
final class StarLevels
{
    /** Most decimals a band can show; more is rounded for display only. */
    private const MAX_DECIMALS = 6;

    /** @var array<int, array{string, string}> */
    private const TEXT = [
        1 => ['Beginner', 'New to pickleball'],
        2 => ['Novice', 'Learning the rules and basic strokes'],
        3 => ['Intermediate', 'Consistent rallies, learning dinks'],
        4 => ['Upper intermediate', 'Solid all-round, third-shot drops'],
        5 => ['Advanced', 'Strategic play'],
        6 => ['Expert', 'Tournament level'],
    ];

    /**
     * @return list<StarLevel>
     */
    public static function forClub(Club $club): array
    {
        return self::forBands($club->star_bands);
    }

    /**
     * @param  array<array-key, float|int|string>  $bands  Five ascending thresholds.
     * @return list<StarLevel>
     *
     * @throws \InvalidArgumentException when the bands are not five ascending numbers
     */
    public static function forBands(array $bands = StarRating::DEFAULT_BANDS): array
    {
        $b = array_map(fn ($v): float => (float) $v, array_values($bands));
        StarRating::assertValid($b);

        $levels = [];
        foreach (self::TEXT as $stars => [$label, $hint]) {
            $min = $stars === 1 ? null : $b[$stars - 2];
            $max = null;
            $inclusive = false;
            if ($stars < 6) {
                $upper = $b[$stars - 1];
                if ($stars > 1 && self::onGrid($upper)) {
                    $max = round($upper - 0.01, 2);
                    $inclusive = true;
                } else {
                    $max = $upper;
                }
            }

            $levels[] = [
                'stars' => $stars,
                'label' => $label,
                'hint' => $hint,
                'min' => $min,
                'max' => $max,
                'max_inclusive' => $inclusive,
                'decimals' => self::decimals($min, $max),
            ];
        }

        return $levels;
    }

    private static function onGrid(float $value): bool
    {
        return abs($value * 100 - round($value * 100)) < 1e-9;
    }

    private static function decimals(?float $min, ?float $max): int
    {
        for ($d = 2; $d < self::MAX_DECIMALS; $d++) {
            if (($min === null || abs($min - round($min, $d)) < 1e-9) && ($max === null || abs($max - round($max, $d)) < 1e-9)) {
                return $d;
            }
        }

        return self::MAX_DECIMALS;
    }
}
