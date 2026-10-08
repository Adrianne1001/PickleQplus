<?php

namespace App\Livewire\Inputs;

use App\Concerns\PlayerValidationRules;
use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Rules\DuprPlayerId;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns the roster modal's raw Livewire input into validated PlayerService data.
 * Livewire shows the thrown ValidationException's errors next to the fields.
 */
class PlayerInput
{
    use PlayerValidationRules;

    /**
     * @return array{name: string, gender?: string|null, dupr_id: string|null, dupr_rating: string|null, rating_source: string, stars: int|string|null}
     *
     * @throws ValidationException
     */
    public function validate(Club $club, ?Player $player, string $name, string $duprId, string $rating, string $source, string $stars, ?string $gender = null): array
    {
        $rating = trim($rating);
        $hasRating = $rating !== '';
        // Without a rating the stars can only be manual.
        $source = $hasRating ? $source : RatingSource::Manual->value;
        $manual = $source === RatingSource::Manual->value;
        $stars = trim($stars);

        $input = [
            'name' => trim($name),
            'dupr_id' => DuprPlayerId::normalize($duprId),
            'dupr_rating' => $hasRating ? $rating : null,
            'rating_source' => $source,
            // Stars only matter when they are set by hand.
            'stars' => $manual && $stars !== '' ? $stars : null,
        ];

        // null leaves the gender untouched; blank (or an invalid value, which fails validation) clears or rejects it.
        if ($gender !== null) {
            $gender = trim($gender);
            $input['gender'] = $gender === '' ? null : $gender;
        }

        $rules = $this->playerRules($club, $player, $input);
        if (! $manual) {
            // A DUPR-sourced player never needs manual stars.
            $rules['stars'] = ['nullable'];
        }

        /** @var array{name: string, gender?: string|null, dupr_id: string|null, dupr_rating: string|null, rating_source: string, stars: int|string|null} */
        return Validator::make($input, $rules)->validate();
    }
}
