<?php

namespace Database\Factories;

use App\Enums\SessionPlayerStatus;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
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

    /**
     * Everyone checked in is on the session's list (Phase 16), as CheckInService guarantees.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (SessionPlayer $entry): void {
            SessionListPlayer::query()->insertOrIgnore([[
                'play_session_id' => $entry->play_session_id,
                'player_id' => $entry->player_id,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        });
    }

    public function status(SessionPlayerStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
