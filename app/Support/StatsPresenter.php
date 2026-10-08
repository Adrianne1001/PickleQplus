<?php

namespace App\Support;

/**
 * Small presentation helpers for the stats tables (initials, avatar colour, durations).
 */
final class StatsPresenter
{
    /** Static class strings so Tailwind sees them. */
    private const AVATARS = [
        'bg-rose-100 text-rose-800 dark:bg-rose-500/25 dark:text-rose-200',
        'bg-orange-100 text-orange-800 dark:bg-orange-500/25 dark:text-orange-200',
        'bg-amber-100 text-amber-900 dark:bg-amber-500/25 dark:text-amber-200',
        'bg-lime-100 text-lime-900 dark:bg-lime-500/25 dark:text-lime-200',
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/25 dark:text-emerald-200',
        'bg-teal-100 text-teal-800 dark:bg-teal-500/25 dark:text-teal-200',
        'bg-sky-100 text-sky-800 dark:bg-sky-500/25 dark:text-sky-200',
        'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/25 dark:text-indigo-200',
        'bg-violet-100 text-violet-800 dark:bg-violet-500/25 dark:text-violet-200',
        'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-500/25 dark:text-fuchsia-200',
    ];

    /** Up to two initials: first letters of the first and last word. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '?';
        }
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /** Same name, same colour, every time. */
    public static function avatarClasses(string $name): string
    {
        return self::AVATARS[crc32(mb_strtolower(trim($name))) % count(self::AVATARS)];
    }

    /** "12 min", "1 h", "1 h 5 min", or "–" when unknown. */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '–';
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? $h.' h' : $h.' h '.$m.' min';
    }
}
