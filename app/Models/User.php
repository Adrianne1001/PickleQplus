<?php

namespace App\Models;

use App\Enums\ClubRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_club_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'current_club_id' => 'integer',
        ];
    }

    /**
     * Clubs this user belongs to, with the role on the pivot.
     *
     * @return BelongsToMany<Club, $this, ClubMembership>
     */
    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'club_user')
            ->using(ClubMembership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @var array<int, ClubRole|null> */
    private array $roleCache = [];

    private int $roleCacheEpoch = 0;

    /** Bumped whenever memberships change through ClubService; invalidates every instance's cache. */
    private static int $roleEpoch = 0;

    public static function flushRoleCache(): void
    {
        self::$roleEpoch++;
    }

    /**
     * The user's role in a club, looked up once per club per model instance.
     */
    public function roleIn(Club $club): ?ClubRole
    {
        if ($this->roleCacheEpoch !== self::$roleEpoch) {
            $this->roleCache = [];
            $this->roleCacheEpoch = self::$roleEpoch;
        }

        if (! array_key_exists($club->id, $this->roleCache)) {
            $this->roleCache[$club->id] = ClubMembership::query()
                ->where('club_id', $club->id)
                ->where('user_id', $this->id)
                ->first()?->role;
        }

        return $this->roleCache[$club->id];
    }

    public function isOwnerOf(Club $club): bool
    {
        return $this->roleIn($club) === ClubRole::Owner;
    }

    public function belongsToClub(Club $club): bool
    {
        return $this->roleIn($club) !== null;
    }

    /**
     * The clubs for the sidebar switcher: ordered by name, pivot role loaded.
     *
     * @return Collection<int, Club>
     */
    public function switcherClubs(): Collection
    {
        return $this->clubs()->orderBy('clubs.name')->get();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
