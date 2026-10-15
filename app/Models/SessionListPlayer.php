<?php

namespace App\Models;

use App\Enums\ListSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One player on one session's player list (Phase 16). A listed player with no
 * session_players row is "Not Yet Here".
 *
 * @property int $id
 * @property int $play_session_id
 * @property int $player_id
 * @property ListSource $source
 * @property int|null $added_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SessionListPlayer extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['source' => ListSource::class];
    }

    /**
     * @return BelongsTo<PlaySession, $this>
     */
    public function playSession(): BelongsTo
    {
        return $this->belongsTo(PlaySession::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
