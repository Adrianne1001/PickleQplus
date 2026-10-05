<?php

namespace App\Domain\Stars;

use InvalidArgumentException;

/**
 * Pure-PHP rating to star conversion. No framework dependencies.
 *
 * Bands are five strictly ascending thresholds. A rating below the first
 * threshold is 1 star, and a rating at or above the last one is 6 stars.
 */
final class StarRating
{
    public const DEFAULT_BANDS = [2.50, 3.00, 3.50, 4.00, 4.50];

    public const MIN_THRESHOLD = 2.0;

    public const MAX_THRESHOLD = 8.0;

    public const MIN_STARS = 1;

    public const MAX_STARS = 6;

    /**
     * @param  array<array-key, float|int>  $bands  Five ascending thresholds.
     */
    public static function fromRating(float $rating, array $bands = self::DEFAULT_BANDS): int
    {
        self::assertValid($bands);

        $stars = self::MIN_STARS;

        foreach (array_values($bands) as $threshold) {
            if ($rating >= $threshold) {
                $stars++;
            }
        }

        return $stars;
    }

    /**
     * @param  array<array-key, mixed>  $bands
     * @return list<string> Error messages; empty when valid.
     */
    public static function validate(array $bands): array
    {
        $values = array_values($bands);

        if (count($values) !== 5) {
            return ['Star bands must contain exactly 5 values.'];
        }

        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return ['Star bands must be numeric.'];
            }
            if ($value < self::MIN_THRESHOLD || $value > self::MAX_THRESHOLD) {
                return ['Each star band must be between 2.0 and 8.0.'];
            }
        }

        for ($i = 1; $i < 5; $i++) {
            if ($values[$i] <= $values[$i - 1]) {
                return ['Star bands must be strictly ascending.'];
            }
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $bands
     */
    public static function assertValid(array $bands): void
    {
        $errors = self::validate($bands);

        if ($errors !== []) {
            throw new InvalidArgumentException($errors[0]);
        }
    }
}
