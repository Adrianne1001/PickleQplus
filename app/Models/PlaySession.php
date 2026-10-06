<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Database\Factories\PlaySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $club_id
 * @property string $name
 * @property Carbon $date
 * @property int $courts
 * @property array{type: string, games: int, to: int, win_by: int} $scoring
 * @property SessionStatus $status
 * @property string|null $checkin_token
 * @property int $up_next_count
 * @property bool $auto_fill
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'date', 'courts', 'scoring', 'up_next_count', 'auto_fill'])]
class PlaySession extends Model
{
    /** @use HasFactory<PlaySessionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'courts' => 'integer',
            'status' => SessionStatus::class,
            'up_next_count' => 'integer',
            'auto_fill' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Scoring config in a fixed key order (type, games, to, win_by), because
     * MySQL's JSON type re-orders object keys.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function scoring(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                /** @var array<string, mixed> $decoded */
                $decoded = $value === null ? [] : (array) json_decode($value, true);
                $ordered = [];
                foreach (['type', 'games', 'to', 'win_by'] as $key) {
                    if (array_key_exists($key, $decoded)) {
                        $ordered[$key] = $decoded[$key];
                        unset($decoded[$key]);
                    }
                }

                return $ordered + $decoded;
            },
            set: fn (array $value): string => (string) json_encode($value),
        );
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @return HasMany<SessionPlayer, $this>
     */
    public function sessionPlayers(): HasMany
    {
        return $this->hasMany(SessionPlayer::class);
    }

    /**
     * @return HasMany<GameMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function isDraft(): bool
    {
        return $this->status === SessionStatus::Draft;
    }

    public function isLive(): bool
    {
        return $this->status === SessionStatus::Live;
    }

    public function isEnded(): bool
    {
        return $this->status === SessionStatus::Ended;
    }
}
