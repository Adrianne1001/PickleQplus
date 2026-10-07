<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\RatingSource;
use App\Rules\DuprPlayerId;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $club_id
 * @property string $name
 * @property string|null $public_id
 * @property string|null $nickname
 * @property Gender|null $gender
 * @property Carbon|null $self_registered_at
 * @property int|null $self_registered_session_id
 * @property string|null $dupr_id
 * @property numeric-string|null $dupr_rating
 * @property int $stars
 * @property RatingSource $rating_source
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'nickname', 'gender', 'dupr_id', 'dupr_rating', 'stars', 'rating_source', 'active'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dupr_rating' => 'decimal:3',
            'stars' => 'integer',
            'rating_source' => RatingSource::class,
            'gender' => Gender::class,
            'active' => 'boolean',
            'self_registered_at' => 'datetime',
        ];
    }

    /**
     * Always stored trimmed and uppercase; blank becomes null.
     *
     * @return Attribute<string|null, mixed>
     */
    protected function duprId(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => DuprPlayerId::normalize($value));
    }

    /**
     * The name shown on public and TV pages: the nickname, else first name plus
     * last initial ("Adrianne B."). A single-word name stays as is.
     */
    public function publicName(): string
    {
        if ($this->nickname !== null && trim($this->nickname) !== '') {
            return $this->nickname;
        }

        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        return $parts[0].' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
    }

    /**
     * Case-insensitive nickname check within a club.
     */
    public static function nicknameTaken(int $clubId, string $nickname, ?int $ignoreId = null): bool
    {
        return self::query()
            ->where('club_id', $clubId)
            ->whereRaw('lower(nickname) = ?', [mb_strtolower($nickname)])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    protected static function booted(): void
    {
        static::creating(function (Player $player): void {
            if ($player->public_id === null) {
                do {
                    $candidate = '';
                    for ($i = 0; $i < 12; $i++) {
                        $candidate .= 'abcdefghijklmnopqrstuvwxyz0123456789'[random_int(0, 35)];
                    }
                } while (self::query()->where('public_id', $candidate)->exists());
                $player->public_id = $candidate;
            }
        });
    }

    /**
     * Resolve a player by public id inside a club (never by sequential id).
     */
    public static function findByPublicId(int $clubId, string $publicId, bool $lock = false): ?self
    {
        return self::query()
            ->where('club_id', $clubId)
            ->where('public_id', $publicId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /**
     * Trim; blank becomes null.
     */
    public static function normalizeNickname(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
