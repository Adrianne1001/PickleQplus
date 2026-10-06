<?php

namespace App\Models;

use Database\Factories\DuprExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One DUPR CSV export of a session. The file lives on the private `local` disk.
 *
 * @property int $id
 * @property int $play_session_id
 * @property int|null $user_id
 * @property int $match_count
 * @property string $file_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['play_session_id', 'user_id', 'match_count', 'file_path'])]
class DuprExport extends Model
{
    /** @use HasFactory<DuprExportFactory> */
    use HasFactory;

    protected $table = 'dupr_exports';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['match_count' => 'integer'];
    }

    /**
     * @return BelongsTo<PlaySession, $this>
     */
    public function playSession(): BelongsTo
    {
        return $this->belongsTo(PlaySession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GameMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'dupr_export_id');
    }
}
