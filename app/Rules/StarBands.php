<?php

namespace App\Rules;

use App\Domain\Stars\StarRating;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StarBands implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be a list of 5 thresholds.');

            return;
        }

        $bands = [];
        foreach ($value as $item) {
            if (! is_numeric($item)) {
                $fail('The :attribute must be numeric.');

                return;
            }
            $bands[] = (float) $item;
        }

        $errors = StarRating::validate($bands);

        if ($errors !== []) {
            $fail($errors[0]);
        }
    }
}
