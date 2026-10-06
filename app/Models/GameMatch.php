<?php

namespace App\Models;

use App\Enums\MatchStatus;
use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Named GameMatch because `match` is a reserved word in PHP 8.
 *
 * @property int $id
 * @property int $play_session_id
 * @property int|null $court_no
 * @property MatchStatus $status
 * @property int|null $team_a_score
 * @property int|null $team_b_score
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property bool $dupr_eligible
 * @property Carbon|null $dupr_exported_at
 * @property int|null $dupr_export_id
 * @property Carbon|null $dupr_synced_at
 * @property string|null $dupr_match_ref
 */
#[Fillable(['play_session_id', 'court_no', 'status', 'team_a_score', 'team_b_score', 'started_at', 'finished_at', 'dupr_eligible'])]
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'court_no' => 'integer',
            'status' => MatchStatus::class,
            'team_a_score' => 'integer',
            'team_b_score' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'dupr_eligible' => 'boolean',
            'dupr_exported_at' => 'datetime',
            'dupr_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlaySession, $this>
     */
    public function playSession(): BelongsTo
    {
        return $this->belongsTo(PlaySession::class);
    }

    /**
     * @return BelongsTo<DuprExport, $this>
     */
    public function duprExport(): BelongsTo
    {
        return $this->belongsTo(DuprExport::class, 'dupr_export_id');
    }

    /**
     * @return HasMany<MatchPlayer, $this>
     */
    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id');
    }
}
