<?php

namespace Database\Factories;

use App\Enums\MatchStatus;
use App\Enums\Team;
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

    /** A finished match with a winner and both scores. */
    public function done(Team $winner = Team::A, int $winnerScore = 11, int $loserScore = 5, int $court = 1): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::Done,
            'court_no' => $court,
            'winner' => $winner,
            'team_a_score' => $winner === Team::A ? $winnerScore : $loserScore,
            'team_b_score' => $winner === Team::A ? $loserScore : $winnerScore,
            'started_at' => now()->subMinutes(15),
            'finished_at' => now(),
        ]);
    }

    /** A finished match with a winner and no scores. */
    public function winnerOnly(Team $winner = Team::A, int $court = 1): static
    {
        return $this->state(fn () => [
            'status' => MatchStatus::Done,
            'court_no' => $court,
            'winner' => $winner,
            'team_a_score' => null,
            'team_b_score' => null,
            'started_at' => now()->subMinutes(15),
            'finished_at' => now(),
        ]);
    }

    public function playing(int $court = 1): static
    {
        return $this->state(fn () => ['status' => MatchStatus::Playing, 'court_no' => $court, 'started_at' => now()]);
    }
}
