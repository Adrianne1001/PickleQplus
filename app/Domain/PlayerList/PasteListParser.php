<?php

namespace App\Domain\PlayerList;

use App\Enums\Gender;

/**
 * Pure parser for a pasted player list (Phase 16). No framework dependencies.
 *
 * UTF-8 text, at most 20 KB and 200 non-blank lines, one player per line. Leading numbering and
 * bullets (`1.`, `1)`, `1 -`, `#1`, `-`, `*`, `•`) are stripped; whitespace is trimmed and
 * collapsed. Optional details follow the name, separated by `|`: `rating=4.2`, `duprId=ABC123`
 * (keys are case-insensitive; `dupr_id`, `duprid` and `dupr` are accepted) and `gender=woman` (keys `gender`,
 * `g`, `sex`; `man`/`m`/`male` and `woman`/`w`/`f`/`female`, any case; an unrecognised value is kept
 * as typed so the review can flag it). Unknown keys are ignored
 * with a row warning.
 */
final class PasteListParser
{
    public const MAX_BYTES = 20480;

    public const MAX_LINES = 200;

    public static function parse(string $text): PasteParseResult
    {
        if (strlen($text) > self::MAX_BYTES) {
            return new PasteParseResult([], 'The list is too long (20 KB at most).');
        }

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            return new PasteParseResult([], 'The list must be UTF-8 text.');
        }

        $players = [];
        $lineNo = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $lineNo++;
            if (trim($raw) === '') {
                continue;
            }
            if (count($players) >= self::MAX_LINES) {
                return new PasteParseResult([], 'The list has more than '.self::MAX_LINES.' players.');
            }

            $players[] = self::parseLine($lineNo, $raw);
        }

        if ($players === []) {
            return new PasteParseResult([], 'Paste at least one player.');
        }

        return new PasteParseResult($players);
    }

    private static function parseLine(int $lineNo, string $raw): PastedPlayer
    {
        $segments = explode('|', $raw);
        $name = self::collapse(self::stripNumbering((string) array_shift($segments)));

        $rating = '';
        $duprId = '';
        $gender = '';
        $warnings = [];
        foreach ($segments as $segment) {
            $segment = self::collapse($segment);
            if ($segment === '') {
                continue;
            }

            if (! str_contains($segment, '=')) {
                $warnings[] = 'Ignored "'.self::shorten($segment).'" (use key=value).';

                continue;
            }

            [$key, $value] = explode('=', $segment, 2);
            $key = mb_strtolower(trim($key));
            $value = trim($value);

            if ($key === 'rating') {
                $rating = $value;
            } elseif (in_array($key, ['duprid', 'dupr_id', 'dupr'], true)) {
                $duprId = $value;
            } elseif (in_array($key, ['gender', 'g', 'sex'], true)) {
                $parsed = Gender::tryParse($value);
                $gender = $parsed === null ? $value : $parsed->value;
            } else {
                $warnings[] = 'Ignored unknown detail "'.self::shorten($key).'".';
            }
        }

        return new PastedPlayer($lineNo, $name, $rating, $duprId, $warnings, $gender);
    }

    /** Remove leading list numbering and bullets, up to three times (e.g. "- 1. Ana"). */
    private static function stripNumbering(string $name): string
    {
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace('/^\s*(?:#\s*\d+[.)]?|\d+\s*[.)]|\d+\s+-|[-*•])\s*/u', '', $name);
            if ($stripped === null || $stripped === $name) {
                break;
            }
            $name = $stripped;
        }

        return $name;
    }

    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function shorten(string $value): string
    {
        return mb_strlen($value) > 30 ? mb_substr($value, 0, 29).'…' : $value;
    }
}
