<?php

namespace App\Concerns;

use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Rules\DuprPlayerId;
use Illuminate\Validation\Rule;

trait PlayerValidationRules
{
    /**
     * Rules for creating ($player null) or updating a player in a club.
     * Normalize dupr_id with DuprPlayerId::normalize() before validating.
     *
     * @param  array<string, mixed>  $input  The raw input; used to decide whether stars are required on create.
     * @return array<string, array<int, mixed>>
     */
    protected function playerRules(Club $club, ?Player $player = null, array $input = []): array
    {
        $min = (float) config('pickleq.rating_min');
        $max = (float) config('pickleq.rating_max');
        $updating = $player !== null;

        $rating = $input['dupr_rating'] ?? null;
        $starsRequired = ! $updating && (
            $rating === null
            || $rating === ''
            || ($input['rating_source'] ?? null) === RatingSource::Manual->value
        );

        return [
            'name' => $updating ? ['sometimes', 'required', 'string', 'max:120'] : ['required', 'string', 'max:120'],
            'dupr_id' => [
                'sometimes',
                'nullable',
                'string',
                new DuprPlayerId,
                Rule::unique('players', 'dupr_id')
                    ->where('club_id', $club->id)
                    ->ignore($player?->id),
            ],
            'dupr_rating' => ['sometimes', 'nullable', 'numeric', "between:{$min},{$max}", 'decimal:0,3'],
            'rating_source' => ['sometimes', 'nullable', Rule::enum(RatingSource::class)],
            'stars' => array_merge(
                $starsRequired ? ['required'] : ['sometimes', 'nullable'],
                ['integer', 'between:1,6'],
            ),
        ];
    }
}
