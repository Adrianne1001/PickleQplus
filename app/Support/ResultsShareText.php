<?php

namespace App\Support;

/**
 * Plain-text snippets for sharing a session's results (link previews, share sheets).
 */
final class ResultsShareText
{
    /**
     * "🥇 A · 🥈 B · 🥉 C" for the podium rows (rank decides the medal, so ties share one).
     *
     * @param  list<array{rank: int|null, name: string}>  $podium
     */
    public static function podiumLine(array $podium): string
    {
        $parts = [];
        foreach ($podium as $row) {
            $parts[] = self::medal($row['rank']).' '.$row['name'];
        }

        return implode(' · ', $parts);
    }

    public static function medal(?int $rank): string
    {
        return match (true) {
            $rank === 1 => '🥇',
            $rank === 2 => '🥈',
            default => '🥉',
        };
    }
}
