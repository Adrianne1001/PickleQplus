<?php

namespace Database\Factories;

use App\Domain\Stars\StarRating;
use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Unrated, manual-stars player by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name' => fake()->name(),
            'dupr_id' => null,
            'dupr_rating' => null,
            'stars' => fake()->numberBetween(1, 6),
            'rating_source' => RatingSource::Manual,
            'active' => true,
        ];
    }

    /**
     * DUPR-sourced rating; stars derived from the default bands.
     */
    public function rated(float $rating, ?string $duprId = null): static
    {
        return $this->state(fn () => [
            'dupr_id' => $duprId ?? Str::upper(Str::random(6)),
            'dupr_rating' => number_format($rating, 3, '.', ''),
            'stars' => StarRating::fromRating($rating, config('pickleq.star_bands')),
            'rating_source' => RatingSource::Dupr,
        ]);
    }

    public function manual(int $stars): static
    {
        return $this->state(fn () => [
            'stars' => $stars,
            'rating_source' => RatingSource::Manual,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
