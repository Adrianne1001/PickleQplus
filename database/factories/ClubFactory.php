<?php

namespace Database\Factories;

use App\Enums\ClubRole;
use App\Enums\LateArrivalPolicy;
use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'dupr_club_id' => null,
            'star_bands' => config('pickleq.star_bands'),
            'default_courts' => 4,
            'late_arrival_policy' => LateArrivalPolicy::Minimum,
            'allow_concurrent_sessions' => false,
        ];
    }

    /**
     * Attach an owner (a new verified user unless one is given).
     */
    public function withOwner(?User $owner = null): static
    {
        return $this->afterCreating(function (Club $club) use ($owner): void {
            $club->users()->attach(
                ($owner ?? User::factory()->create())->id,
                ['role' => ClubRole::Owner->value],
            );
            User::flushRoleCache();
        });
    }

    /**
     * Attach a staff member (a new verified user unless one is given).
     */
    public function withStaff(?User $staff = null): static
    {
        return $this->afterCreating(function (Club $club) use ($staff): void {
            $club->users()->attach(
                ($staff ?? User::factory()->create())->id,
                ['role' => ClubRole::Staff->value],
            );
            User::flushRoleCache();
        });
    }
}
