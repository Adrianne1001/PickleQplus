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

    /** Slugs starting with this belong to guest clubs (Phase 14); a normal club may not take one. */
    public const GUEST_PREFIX = 'guest-';

    /**
     * @param  string|null  $unchanged  The club's current slug, which stays valid when it is kept
     *                                  (a claimed guest club keeps its `guest-` slug).
     */
    public function __construct(private readonly ?string $unchanged = null) {}

    public static function usesGuestPrefix(string $slug): bool
    {
        return str_starts_with($slug, self::GUEST_PREFIX);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute may only contain lowercase letters, numbers and single hyphens.');

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail('The :attribute is reserved.');

            return;
        }

        if (self::usesGuestPrefix($value) && $value !== $this->unchanged) {
            $fail('The :attribute may not start with "'.self::GUEST_PREFIX.'".');
        }
    }
}
