<?php

namespace App\Models;

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
 * @property string|null $dupr_id
 * @property numeric-string|null $dupr_rating
 * @property int $stars
 * @property RatingSource $rating_source
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'dupr_id', 'dupr_rating', 'stars', 'rating_source', 'active'])]
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
            'active' => 'boolean',
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
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
