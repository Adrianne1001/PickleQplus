<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A player DUPR ID: 6 alphanumeric characters, stored uppercase.
 * Reused by the roster import.
 */
class DuprPlayerId implements ValidationRule
{
    public const PATTERN = '/^[A-Z0-9]{6}\z/';

    /**
     * Trim and uppercase; blank becomes null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value === '' ? null : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = self::normalize($value);

        if ($normalized !== null && preg_match(self::PATTERN, $normalized) !== 1) {
            $fail('The :attribute must be 6 letters or digits.');
        }
    }
}
