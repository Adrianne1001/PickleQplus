<?php

namespace App\Enums;

enum Gender: string
{
    case Man = 'man';
    case Woman = 'woman';

    /**
     * Parse a free-text value (form or CSV): man, woman, m, w, male, female, f,
     * in any case. Returns null for anything else, including blank.
     */
    public static function tryParse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'man', 'm', 'male' => self::Man,
            'woman', 'w', 'female', 'f' => self::Woman,
            default => null,
        };
    }

    public function label(): string
    {
        return $this === self::Man ? 'Man' : 'Woman';
    }
}
