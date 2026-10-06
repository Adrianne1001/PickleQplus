<?php

namespace App\Models;

use App\Enums\Team;
use Database\Factories\MatchPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $match_id
 * @property int $player_id
 * @property Team $team
 * @property int $slot
 */
#[Fillable(['match_id', 'player_id', 'team', 'slot'])]
class MatchPlayer extends Model
{
    /** @use HasFactory<MatchPlayerFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'team' => Team::class,
            'slot' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GameMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
