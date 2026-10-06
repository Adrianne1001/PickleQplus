<?php

namespace Database\Factories;

use App\Enums\Team;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchPlayer>
 */
class MatchPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => GameMatch::factory(),
            'player_id' => Player::factory(),
            'team' => Team::A,
            'slot' => 1,
        ];
    }
}
