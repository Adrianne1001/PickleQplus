<?php

namespace App\Models;

use App\Enums\ClubRole;
use App\Enums\LateArrivalPolicy;
use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $dupr_club_id
 * @property list<float> $star_bands
 * @property int $default_courts
 * @property LateArrivalPolicy $late_arrival_policy
 * @property bool $allow_concurrent_sessions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'dupr_club_id', 'star_bands', 'default_courts', 'late_arrival_policy', 'allow_concurrent_sessions'])]
class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'late_arrival_policy' => 'minimum',
        'allow_concurrent_sessions' => false,
    ];

    /** Container key under which EnsureClubMember binds the current club. */
    public const CONTAINER_KEY = 'currentClub';

    /**
     * The club resolved for the current request (set by EnsureClubMember).
     */
    public static function current(): ?self
    {
        $club = app()->bound(self::CONTAINER_KEY) ? app(self::CONTAINER_KEY) : null;

        return $club instanceof self ? $club : null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'star_bands' => 'array',
            'default_courts' => 'integer',
            'late_arrival_policy' => LateArrivalPolicy::class,
            'allow_concurrent_sessions' => 'boolean',
        ];
    }

    /**
     * Members of the club, with the role on the pivot.
     *
     * @return BelongsToMany<User, $this, ClubMembership>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_user')
            ->using(ClubMembership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, ClubMembership>
     */
    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', ClubRole::Owner->value);
    }

    /**
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * @return HasMany<PlaySession, $this>
     */
    public function playSessions(): HasMany
    {
        return $this->hasMany(PlaySession::class);
    }

    /**
     * Scoped route binding: `{session}` resolves through playSessions().
     *
     * @param  string  $childType
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'session' ? 'playSessions' : parent::childRouteBindingRelationshipName($childType);
    }

    /**
     * @return HasMany<ClubInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ClubInvitation::class);
    }

    /**
     * Invitations that have not been accepted (includes expired ones, so an
     * owner can resend them). Use ClubInvitation::isExpired() to tell them apart.
     *
     * @return HasMany<ClubInvitation, $this>
     */
    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->whereNull('accepted_at')->orderByDesc('created_at');
    }
}
