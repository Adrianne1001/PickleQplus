<?php

namespace App\Concerns;

use App\Enums\LateArrivalPolicy;
use App\Models\Club;
use App\Rules\ClubSlug;
use App\Rules\StarBands;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ClubValidationRules
{
    /**
     * Rules for creating a club (slug is generated).
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function createClubRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'dupr_club_id' => $this->duprClubIdRules(),
            'default_courts' => ['nullable', 'integer', 'between:1,30'],
        ];
    }

    /**
     * Rules for editing a club.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function updateClubRules(Club $club): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:50', new ClubSlug, Rule::unique('clubs', 'slug')->ignore($club->id)],
            'dupr_club_id' => $this->duprClubIdRules(),
            'default_courts' => ['required', 'integer', 'between:1,30'],
        ];
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function starBandsRules(): array
    {
        return [
            'star_bands' => ['required', 'array', new StarBands],
            'star_bands.*' => ['numeric'],
        ];
    }

    /**
     * Owner-only session settings (see ClubService::updateSessionSettings).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sessionSettingsRules(): array
    {
        return [
            'late_arrival_policy' => ['required', Rule::enum(LateArrivalPolicy::class)],
            'allow_concurrent_sessions' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function duprClubIdRules(): array
    {
        return ['nullable', 'string', 'regex:/^\d{10}\z/'];
    }
}
