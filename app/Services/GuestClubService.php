<?php

namespace App\Services;

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Guest sessions (Phase 14): a hidden, temporary club for someone with no account.
 * The plain organizer token is shown to the guest once (cookie and organizer link);
 * only its SHA-256 hash is stored.
 */
class GuestClubService
{
    public const COOKIE_PREFIX = 'pickleq_guest_';

    public function __construct(private readonly PlaySessionService $sessions, private readonly ClubService $clubs) {}

    /**
     * Create the guest club with one draft session. Refuses when too many guest
     * clubs are active (the circuit breaker).
     *
     * @return array{club: Club, session: PlaySession, token: string}
     *
     * @throws ValidationException
     */
    public function start(?string $sessionName = null): array
    {
        $token = Str::random(40);
        $name = $sessionName === null ? '' : trim($sessionName);

        // One lock around the count and the insert, so concurrent starts cannot overshoot max_active.
        $lock = Cache::lock('guest-start', 10);
        try {
            $lock->block((int) config('pickleq.guest.start_lock_wait'));
        } catch (LockTimeoutException) {
            throw $this->busy();
        }

        try {
            return $this->createGuestClub($token, $name);
        } finally {
            $lock->release();
        }
    }

    private function busy(): ValidationException
    {
        return ValidationException::withMessages([
            'guest' => 'Guest sessions are very busy right now. Please try again later, or sign up for a free account.',
        ]);
    }

    /**
     * @return array{club: Club, session: PlaySession, token: string}
     *
     * @throws ValidationException
     */
    private function createGuestClub(string $token, string $name): array
    {
        if (Club::query()->activeGuests()->count() >= (int) config('pickleq.guest.max_active')) {
            throw $this->busy();
        }

        return DB::transaction(function () use ($token, $name): array {
            $club = new Club;
            $club->forceFill([
                'name' => 'Guest session',
                'slug' => 'guest-'.Str::lower(Str::random(12)),
                'star_bands' => config('pickleq.star_bands'),
                'default_courts' => min((int) config('pickleq.default_courts'), (int) config('pickleq.guest.max_courts')),
                'is_guest' => true,
                'guest_token_hash' => self::hash($token),
                'guest_expires_at' => now()->addHours((int) config('pickleq.guest.idle_hours')),
            ])->save();

            $session = $this->sessions->create($club, $name === '' ? [] : ['name' => $name]);

            return ['club' => $club, 'session' => $session, 'token' => $token];
        });
    }

    /**
     * Turn a guest club into a normal club owned by $user (the "Sign up to keep this session" step).
     * Locks the user, then the club (the purge locks the same club row, so claim and purge cannot
     * both win), and re-checks: still a guest, not expired, the token matches, the user may create
     * a club (verified, per the Fortify flag) and is under the owned-club cap. The slug is kept, so
     * live links keep working. First claim wins.
     *
     * @throws ValidationException
     */
    public function claim(Club $club, User $user, string $token, ?string $name = null): Club
    {
        $name = $name === null ? null : trim($name);
        if ($name !== null && mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'The club name may not be longer than 120 characters.']);
        }

        return DB::transaction(function () use ($club, $user, $token, $name): Club {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = Club::query()->whereKey($club->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->is_guest) {
                throw ValidationException::withMessages(['claim' => 'This session has already been claimed, or no longer exists.']);
            }
            if ($locked->isGuestExpired()) {
                throw ValidationException::withMessages(['claim' => 'This guest session has expired.']);
            }
            if (! is_string($locked->guest_token_hash) || ! hash_equals($locked->guest_token_hash, self::hash($token))) {
                throw ValidationException::withMessages(['claim' => 'This session could not be verified. Open your organizer link again.']);
            }
            if (! $user->can('create', Club::class)) {
                throw ValidationException::withMessages(['claim' => 'Verify your email address before claiming a session.']);
            }
            $this->clubs->assertCanOwnAnotherClub($user, 'claim');

            $locked->forceFill([
                'is_guest' => false,
                'guest_token_hash' => null,
                'guest_expires_at' => null,
                'guest_claimed_at' => now(),
            ]);
            if ($name !== null && $name !== '') {
                $locked->name = $name;
            }
            $locked->save();

            $locked->users()->attach($user->id, ['role' => ClubRole::Owner->value]);
            User::flushRoleCache();

            User::query()->whereKey($user->id)->update(['current_club_id' => $locked->id]);
            $user->current_club_id = $locked->id;
            $user->syncOriginalAttribute('current_club_id');

            $club->setRawAttributes($locked->getAttributes(), true);

            return $club;
        });
    }

    /**
     * Was a guest club with this slug purged recently (the 7-day tombstone), and is the slug
     * still free? It lets old links say "expired" instead of a bare 404.
     */
    public static function isGone(string $slug): bool
    {
        return Cache::has('guest-gone:'.$slug) && ! Club::query()->where('slug', $slug)->exists();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return non-empty-string */
    public static function cookieName(Club $club): string
    {
        return self::COOKIE_PREFIX.$club->id;
    }

    /** The unexpired-or-not guest club that this organizer token belongs to. */
    public function findByToken(string $token): ?Club
    {
        $club = Club::query()->where('is_guest', true)->where('guest_token_hash', self::hash($token))->first();

        return $club !== null && is_string($club->guest_token_hash) && hash_equals($club->guest_token_hash, self::hash($token))
            ? $club
            : null;
    }

    /** Does the request carry the organizer cookie of this guest club? */
    public function cookieMatches(Request $request, Club $club): bool
    {
        $token = $this->tokenFromRequest($request, $club);

        return $token !== null
            && $club->is_guest
            && is_string($club->guest_token_hash)
            && hash_equals($club->guest_token_hash, self::hash($token));
    }

    /** The organizer token held in this request's cookie for the club, if any. */
    public function tokenFromRequest(Request $request, Club $club): ?string
    {
        $value = $request->cookie(self::cookieName($club));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The private organizer link for this guest club, read back from the cookie,
     * or null when the request does not carry it.
     */
    public function organizerUrl(Club $club, ?Request $request = null): ?string
    {
        $token = $this->tokenFromRequest($request ?? request(), $club);

        return $token === null ? null : route('guest.enter', $token);
    }

    /**
     * The organizer cookie: encrypted by the web group, HttpOnly, SameSite Lax, and valid
     * until the 72 h cap. Built with the cookie() helper so it follows the session config
     * (secure flag, same site).
     */
    public function cookieFor(Club $club, string $token): Cookie
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($this->hardCap($club), false) / 60));

        return cookie(self::cookieName($club), $token, $minutes, '/', null, null, true, false, 'lax');
    }

    /** The newest moment the club may live to: creation plus max_hours. */
    public function hardCap(Club $club): CarbonImmutable
    {
        return CarbonImmutable::instance($club->created_at ?? now())->addHours((int) config('pickleq.guest.max_hours'));
    }

    /** idle_hours after now, but never past the hard cap. */
    public function expiryFromNow(Club $club): CarbonImmutable
    {
        $idle = CarbonImmutable::instance(now())->addHours((int) config('pickleq.guest.idle_hours'));
        $cap = $this->hardCap($club);

        return $idle->lt($cap) ? $idle : $cap;
    }
}
