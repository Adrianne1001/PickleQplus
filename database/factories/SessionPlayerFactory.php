<?php

namespace Database\Factories;

use App\Enums\SessionPlayerStatus;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionPlayer>
 */
class SessionPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'play_session_id' => PlaySession::factory(),
            'player_id' => Player::factory(),
            'status' => SessionPlayerStatus::Waiting,
            'checked_in_at' => now(),
            'games_played' => 0,
            'games_credit' => 0,
            'queued_at' => now(),
        ];
    }

    public function status(SessionPlayerStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
