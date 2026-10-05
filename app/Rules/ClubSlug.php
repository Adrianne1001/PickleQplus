<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ClubSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * Slugs that would collide with fixed routes under /clubs.
     *
     * @var list<string>
     */
    public const RESERVED = ['create', 'new'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute may only contain lowercase letters, numbers and single hyphens.');

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail('The :attribute is reserved.');
        }
    }
}
