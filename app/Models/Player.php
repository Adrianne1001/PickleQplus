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
#[Fillable(['name', 'gender', 'dupr_id', 'dupr_rating', 'stars', 'rating_source', 'active'])]
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
     * Case-insensitive name check within a club.
     */
    public static function nameTaken(int $clubId, string $name, ?int $ignoreId = null): bool
    {
        return self::query()
            ->where('club_id', $clubId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
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
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
