<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Database\Factories\PlaySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $club_id
 * @property string|null $public_id
 * @property string|null $tv_id
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

    protected static function booted(): void
    {
        static::creating(function (PlaySession $session): void {
            if ($session->public_id === null) {
                do {
                    $candidate = self::randomString(12);
                } while (self::query()->where('public_id', $candidate)->exists());
                $session->public_id = $candidate;
            }

            if ($session->tv_id === null) {
                $session->tv_id = Str::random(32);
            }

            if ($session->checkin_token === null && $session->status !== SessionStatus::Ended) {
                $session->checkin_token = self::newCheckinToken();
            }
        });
    }

    public static function newCheckinToken(): string
    {
        return Str::random(40);
    }

    private static function randomString(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 35)];
        }

        return $out;
    }

    /**
     * Resolve a session by its public id inside a club; null when the session
     * belongs to another club or doesn't exist.
     */
    public static function findByPublicId(Club $club, string $publicId): ?self
    {
        return self::query()->where('club_id', $club->id)->where('public_id', $publicId)->first();
    }

    /**
     * @throws ModelNotFoundException
     */
    public static function findByPublicIdOrFail(Club $club, string $publicId): self
    {
        return self::findByPublicId($club, $publicId) ?? throw (new ModelNotFoundException)->setModel(self::class);
    }

    public static function findByTvId(Club $club, string $tvId): ?self
    {
        return self::query()->where('club_id', $club->id)->where('tv_id', $tvId)->first();
    }

    /**
     * @throws ModelNotFoundException
     */
    public static function findByTvIdOrFail(Club $club, string $tvId): self
    {
        return self::findByTvId($club, $tvId) ?? throw (new ModelNotFoundException)->setModel(self::class);
    }

    /** Session for a check-in token, only while it is draft or live. */
    public static function findByCheckinToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::query()
            ->where('checkin_token', $token)
            ->whereIn('status', [SessionStatus::Draft->value, SessionStatus::Live->value])
            ->first();
    }

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

    /**
     * @return HasMany<DuprExport, $this>
     */
    public function duprExports(): HasMany
    {
        return $this->hasMany(DuprExport::class);
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
