<?php

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaySession>
 */
class PlaySessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'name' => 'Open Play',
            'date' => now()->toDateString(),
            'courts' => 4,
            'scoring' => ['type' => 'side_out', 'games' => 1, 'to' => 11, 'win_by' => 2],
            'status' => SessionStatus::Draft,
            'up_next_count' => 1,
            'auto_fill' => false,
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['status' => SessionStatus::Live, 'started_at' => now()]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => SessionStatus::Ended,
            'started_at' => now()->subHours(2),
            'ended_at' => now(),
        ]);
    }
}
