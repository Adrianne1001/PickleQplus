<?php

namespace Database\Factories;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\PlaySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameMatch>
 */
class GameMatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'play_session_id' => PlaySession::factory(),
            'court_no' => null,
            'status' => MatchStatus::Staged,
            'dupr_eligible' => true,
        ];
    }

    public function playing(int $court = 1): static
    {
        return $this->state(fn () => ['status' => MatchStatus::Playing, 'court_no' => $court, 'started_at' => now()]);
    }
}
