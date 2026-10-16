<?php

namespace App\Support;

use App\Domain\Stars\StarLevels;

/**
 * Display text for a star level row from App\Domain\Stars\StarLevels, shared by the star picker
 * and the board's skill stars so they read the same everywhere. All wording for the DUPR range
 * lives here (translatable), the numbers come from StarLevels.
 *
 * @phpstan-import-type StarLevel from StarLevels
 */
final class StarLevelText
{
    /**
     * "DUPR ~3.00–3.49", "DUPR below 2.50" or "DUPR 4.50+".
     *
     * @param  StarLevel  $level
     */
    public static function dupr(array $level): string
    {
        $open = $level['min'] === null || $level['max'] === null;

        return __('DUPR :range', ['range' => ($open ? '' : '~').self::range($level)]);
    }

    /**
     * "below 2.50", "2.50–2.99", "4.50+", or "2.500 to under 3.005" when the band is not on the
     * 0.01 grid.
     *
     * @param  StarLevel  $level
     */
    public static function range(array $level): string
    {
        $min = $level['min'] === null ? null : number_format($level['min'], $level['decimals'], '.', '');
        $max = $level['max'] === null ? null : number_format($level['max'], $level['decimals'], '.', '');

        return match (true) {
            $min === null => __('below :max', ['max' => (string) $max]),
            $max === null => __(':min+', ['min' => $min]),
            $level['max_inclusive'] => $min.'–'.$max,
            default => __(':min to under :max', ['min' => $min, 'max' => $max]),
        };
    }

    /**
     * One short line for the board: "Intermediate (~3.00–3.49 DUPR)", "Beginner (below 2.50 DUPR)",
     * "Expert (4.50+ DUPR)".
     *
     * @param  StarLevel  $level
     */
    public static function short(array $level): string
    {
        $open = $level['min'] === null || $level['max'] === null;

        return __($level['label']).' ('.($open ? '' : '~').__(':range DUPR', ['range' => self::range($level)]).')';
    }

    /**
     * "★3 · Intermediate".
     *
     * @param  StarLevel  $level
     */
    public static function title(array $level): string
    {
        return '★'.$level['stars'].' · '.__($level['label']);
    }

    /**
     * "Consistent rallies, learning dinks · DUPR ~3.00–3.49".
     *
     * @param  StarLevel  $level
     */
    public static function detail(array $level): string
    {
        return __($level['hint']).' · '.self::dupr($level);
    }
}
