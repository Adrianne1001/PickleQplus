<?php

namespace App\Services;

use App\Domain\Stars\StarRating;
use App\Enums\ClubRole;
use App\Enums\LateArrivalPolicy;
use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Player;
use App\Models\User;
use App\Rules\ClubSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClubService
{
    /** Attempts at inserting a club when a concurrent create takes the same slug. */
    private const SLUG_ATTEMPTS = 5;

    public const SLUG_MAX = 50;

    /**
     * Trim name and slug, and turn a blank dupr_club_id into null. Only keys
     * that are present are touched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('slug', $data)) {
            $data['slug'] = Str::lower(trim((string) $data['slug']));
        }

        if (array_key_exists('dupr_club_id', $data)) {
            $id = $data['dupr_club_id'] === null ? '' : trim((string) $data['dupr_club_id']);
            $data['dupr_club_id'] = $id === '' ? null : $id;
        }

        return $data;
    }

    /**
     * Create a club and attach the creator as owner (one transaction).
     * The slug is generated from the name and made unique; if a concurrent
     * create takes it first, the next free suffix is tried.
     *
     * @param  array{name: string, dupr_club_id?: string|null, default_courts?: int|null}  $data
     *
     * @throws ValidationException when the user already owns the maximum number of clubs.
     */
    public function create(User $creator, array $data): Club
    {
        /** @var array{name: string, dupr_club_id?: string|null, default_courts?: int|null} $data */
        $data = self::normalize($data);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->createOnce($creator, $data);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::SLUG_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param  array{name: string, dupr_club_id?: string|null, default_courts?: int|null}  $data
     */
    private function createOnce(User $creator, array $data): Club
    {
        return DB::transaction(function () use ($creator, $data): Club {
            // Lock the user row so concurrent creates cannot exceed the cap.
            User::query()->whereKey($creator->id)->lockForUpdate()->first();

            $owned = ClubMembership::query()
                ->where('user_id', $creator->id)
                ->where('role', ClubRole::Owner->value)
                ->count();

            if ($owned >= (int) config('pickleq.max_owned_clubs')) {
                throw ValidationException::withMessages([
                    'name' => 'You already own the maximum of '.config('pickleq.max_owned_clubs').' clubs, so you cannot create another one.',
                ]);
            }

            $club = Club::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'dupr_club_id' => $data['dupr_club_id'] ?? null,
                'star_bands' => config('pickleq.star_bands'),
                'default_courts' => $data['default_courts'] ?? (int) config('pickleq.default_courts'),
            ]);

            $club->users()->attach($creator->id, ['role' => ClubRole::Owner->value]);
            User::flushRoleCache();

            return $club;
        });
    }

    /**
     * @param  array{name?: string, slug?: string, dupr_club_id?: string|null, default_courts?: int}  $data
     *
     * @throws ValidationException when the slug is taken.
     */
    public function update(Club $club, array $data): Club
    {
        /** @var array{name?: string, slug?: string, dupr_club_id?: string|null, default_courts?: int} $data */
        $data = self::normalize($data);

        try {
            $club->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That slug is already taken.']);
        }

        return $club;
    }

    /**
     * Owner-only session settings: late arrival policy and whether several
     * sessions may be live at once. Only keys present in $data change. The
     * club row is locked so a concurrent session start sees a consistent value.
     *
     * @param  array{late_arrival_policy?: LateArrivalPolicy|string, allow_concurrent_sessions?: bool}  $data
     *
     * @throws ValidationException
     */
    public function updateSessionSettings(Club $club, array $data): Club
    {
        $validated = Validator::make($data, [
            'late_arrival_policy' => ['sometimes', 'required', Rule::enum(LateArrivalPolicy::class)],
            'allow_concurrent_sessions' => ['sometimes', 'required', 'boolean'],
        ])->validate();

        DB::transaction(function () use ($club, $validated): void {
            Club::query()->whereKey($club->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('late_arrival_policy', $validated)) {
                $policy = $validated['late_arrival_policy'];
                $club->late_arrival_policy = $policy instanceof LateArrivalPolicy ? $policy : LateArrivalPolicy::from((string) $policy);
            }
            if (array_key_exists('allow_concurrent_sessions', $validated)) {
                $club->allow_concurrent_sessions = (bool) $validated['allow_concurrent_sessions'];
            }

            $club->save();
        });

        return $club;
    }

    /**
     * Owner-only stats settings: public stats pages and the leaderboard
     * minimum games (1-100). Only keys present in $data change.
     *
     * @param  array{public_stats?: bool, leaderboard_min_games?: int|string}  $data
     *
     * @throws ValidationException
     */
    public function updateStatsSettings(Club $club, array $data): Club
    {
        $validated = Validator::make($data, [
            'public_stats' => ['sometimes', 'required', 'boolean'],
            'leaderboard_min_games' => ['sometimes', 'required', 'integer', 'between:1,100'],
        ])->validate();

        if (array_key_exists('public_stats', $validated)) {
            $club->public_stats = (bool) $validated['public_stats'];
        }
        if (array_key_exists('leaderboard_min_games', $validated)) {
            $club->leaderboard_min_games = (int) $validated['leaderboard_min_games'];
        }

        $club->save();

        return $club;
    }

    public function delete(Club $club): void
    {
        DB::transaction(function () use ($club): void {
            $club->delete();
            User::flushRoleCache();
        });

        // Exports cascade in the DB; remove their files once the delete has committed.
        Storage::disk('local')->deleteDirectory('dupr-exports/'.$club->id);
    }

    /**
     * Add a member (used by invite acceptance). An existing member is left
     * untouched: their role is never changed here (use changeRole for that).
     *
     * @return bool true when the user was added, false when already a member
     */
    public function addMember(Club $club, User $user, ClubRole $role): bool
    {
        $exists = ClubMembership::query()
            ->where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->exists();

        $added = false;

        if (! $exists) {
            try {
                // A savepoint inside callers' transactions, so a lost race stays harmless.
                DB::transaction(fn () => $club->users()->attach($user->id, ['role' => $role->value]));
                $added = true;
            } catch (UniqueConstraintViolationException) {
                // Added concurrently: treat as already a member.
            }
        }

        User::flushRoleCache();

        return $added;
    }

    /**
     * @throws ValidationException when demoting the last owner.
     */
    public function changeRole(Club $club, User $member, ClubRole $role): void
    {
        DB::transaction(function () use ($club, $member, $role): void {
            $this->guardLastOwner($club, $member, $role);

            ClubMembership::query()
                ->where('club_id', $club->id)
                ->where('user_id', $member->id)
                ->update(['role' => $role->value, 'updated_at' => now()]);

            User::flushRoleCache();
        });
    }

    /**
     * Remove a member (or let them leave).
     *
     * @throws ValidationException when removing the last owner.
     */
    public function removeMember(Club $club, User $member): void
    {
        DB::transaction(function () use ($club, $member): void {
            $this->guardLastOwner($club, $member, null);

            $club->users()->detach($member->id);
            User::flushRoleCache();

            User::query()
                ->whereKey($member->id)
                ->where('current_club_id', $club->id)
                ->update(['current_club_id' => null]);
            if ($member->current_club_id === $club->id) {
                $member->current_club_id = null;
            }
        });
    }

    /**
     * Replace the club's star bands and recompute stars for every
     * dupr-sourced player with a rating, in one transaction.
     *
     * @param  array<array-key, float|int|string>  $bands
     *
     * @throws ValidationException when the bands are invalid.
     */
    public function updateStarBands(Club $club, array $bands): Club
    {
        $normalized = array_map(fn ($b): float => (float) $b, array_values($bands));

        $errors = StarRating::validate($normalized);
        if ($errors !== []) {
            throw ValidationException::withMessages(['star_bands' => $errors[0]]);
        }

        DB::transaction(function () use ($club, $normalized): void {
            // Serialize with player saves that derive stars from the bands.
            Club::query()->whereKey($club->id)->lockForUpdate()->first();

            $club->star_bands = $normalized;
            $club->save();

            $club->players()
                ->where('rating_source', RatingSource::Dupr->value)
                ->whereNotNull('dupr_rating')
                ->chunkById(200, function ($players) use ($normalized): void {
                    /** @var Player $player */
                    foreach ($players as $player) {
                        $stars = StarRating::fromRating((float) $player->dupr_rating, $normalized);

                        if ($stars !== $player->stars) {
                            $player->stars = $stars;
                            $player->save();
                        }
                    }
                });
        });

        return $club;
    }

    /**
     * Slug from a name, suffixed -2, -3, ... until unique and not reserved.
     * Always at most 50 characters (the suffix is made to fit).
     */
    public function uniqueSlug(string $name, ?Club $ignore = null): string
    {
        $base = Str::slug($name);
        $base = $base === '' ? 'club' : $base;
        $ignoreId = $ignore?->id;

        $slug = $this->slugCandidate($base, 1);
        for ($i = 2; $this->slugTaken($slug, $ignoreId); $i++) {
            $slug = $this->slugCandidate($base, $i);
        }

        return $slug;
    }

    private function slugCandidate(string $base, int $n): string
    {
        $suffix = $n === 1 ? '' : '-'.$n;

        return rtrim(substr($base, 0, self::SLUG_MAX - strlen($suffix)), '-').$suffix;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return in_array($slug, ClubSlug::RESERVED, true)
            || Club::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();
    }

    /**
     * @throws ValidationException
     */
    private function guardLastOwner(Club $club, User $member, ?ClubRole $newRole): void
    {
        if ($newRole === ClubRole::Owner) {
            return;
        }

        $owners = ClubMembership::query()
            ->where('club_id', $club->id)
            ->where('role', ClubRole::Owner->value)
            ->lockForUpdate()
            ->pluck('user_id');

        if ($owners->count() === 1 && $owners->first() === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'A club must keep at least one owner.',
            ]);
        }
    }
}
