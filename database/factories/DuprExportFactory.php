<?php

namespace Database\Factories;

use App\Models\DuprExport;
use App\Models\PlaySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DuprExport>
 */
class DuprExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'play_session_id' => PlaySession::factory()->ended(),
            'user_id' => null,
            'match_count' => 1,
            'file_path' => 'dupr-exports/0/0.csv',
        ];
    }
}
