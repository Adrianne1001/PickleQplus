<?php

namespace App\Livewire\Inputs;

use App\Concerns\ClubValidationRules;
use App\Models\Club;
use App\Services\ClubService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates the club forms' raw Livewire input with the shared club rules.
 * Input is normalized (ClubService::normalize) before validation, because
 * Livewire update requests skip TrimStrings and ConvertEmptyStringsToNull.
 * Livewire shows the thrown ValidationException's errors next to the fields.
 */
class ClubInput
{
    use ClubValidationRules;

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, dupr_club_id: string|null, default_courts: int|null}
     *
     * @throws ValidationException
     */
    public function create(array $input): array
    {
        /** @var array<string, mixed> $data */
        $data = Validator::make(ClubService::normalize($input), $this->createClubRules())->validate();

        return [
            'name' => (string) $data['name'],
            'dupr_club_id' => $data['dupr_club_id'] ?? null,
            'default_courts' => ! isset($data['default_courts']) || $data['default_courts'] === ''
                ? null
                : (int) $data['default_courts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, slug: string, dupr_club_id: string|null, default_courts: int}
     *
     * @throws ValidationException
     */
    public function update(Club $club, array $input): array
    {
        /** @var array<string, mixed> $data */
        $data = Validator::make(ClubService::normalize($input), $this->updateClubRules($club))->validate();

        return [
            'name' => (string) $data['name'],
            'slug' => (string) $data['slug'],
            'dupr_club_id' => $data['dupr_club_id'] ?? null,
            'default_courts' => (int) $data['default_courts'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $bands
     * @return array<int, float|int|string>
     *
     * @throws ValidationException
     */
    public function starBands(array $bands): array
    {
        /** @var array{star_bands: array<int, float|int|string>} $data */
        $data = Validator::make(['star_bands' => $bands], $this->starBandsRules())->validate();

        return $data['star_bands'];
    }
}
