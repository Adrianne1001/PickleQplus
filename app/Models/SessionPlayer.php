<?php

namespace App\Models;

use App\Enums\SessionPlayerStatus;
use Database\Factories\SessionPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $play_session_id
 * @property int $player_id
 * @property SessionPlayerStatus $status
 * @property Carbon|null $checked_in_at
 * @property int $games_played
 * @property int $games_credit
 * @property Carbon|null $queued_at
 * @property Carbon|null $last_finished_at
 */
#[Fillable(['play_session_id', 'player_id', 'checked_in_at', 'queued_at', 'last_finished_at'])]
class SessionPlayer extends Model
{
    /** @use HasFactory<SessionPlayerFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionPlayerStatus::class,
            'checked_in_at' => 'datetime',
            'games_played' => 'integer',
            'games_credit' => 'integer',
            'queued_at' => 'datetime',
            'last_finished_at' => 'datetime',
        ];
    }

    /**
     * Entries of players who self-registered in this entry's session
     * (players.self_registered_session_id).
     *
     * @param  Builder<SessionPlayer>  $query
     */
    #[Scope]
    protected function selfRegisteredHere(Builder $query): void
    {
        $query->whereHas('player', fn (Builder $p) => $p->whereColumn('players.self_registered_session_id', 'session_players.play_session_id'));
    }

    public function effectiveGames(): int
    {
        return $this->games_played + $this->games_credit;
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
