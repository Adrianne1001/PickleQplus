<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Public self check-in (reached through the venue QR token). Limits are
 * enforced here, keyed by IP and session, because the UI is a Livewire
 * component whose actions skip route middleware. Every method re-checks the
 * token against the session (inside the session lock when it writes), so a
 * page opened with a replaced QR stops working. Players are identified
 * publicly by players.public_id, never by their sequential id.
 *
 * Phase 16: search and check-in cover only the players on the session's list, and an optional
 * session password is checked (inside the lock, with the token) before any player is looked up.
 * Error keys: `session` (closed or stale token), `password`, `player`, `gender`, `throttle`.
 *
 * @phpstan-type SearchRow array{id: string, name: string, needs_gender: bool, status: 'waiting'|'playing'|'break'|null}
 * @phpstan-type CheckInResult array{result: string, player_id: string, public_name: string}
 */
class SelfCheckInService
{
    use LocksPlaySession;

    public const MIN_QUERY = 2;

    public const MAX_RESULTS = 10;

    public const SEARCH_PER_MINUTE = 60;

    public const SUBMITS_PER_MINUTE = 10;

    private const STALE = 'This QR code has been replaced. Scan the new one.';

    public function __construct(private readonly CheckInService $checkIns) {}

    /**
     * Does the session ask for a password? (A read of the given instance, for the page to decide.)
     */
    public function requiresPassword(PlaySession $session): bool
    {
        return $session->checkin_password !== null && $session->checkin_password !== '';
    }

    /**
     * Has the organizer put anyone on the session's list? False shows "Ask the organizer to add you".
     */
    public function hasList(PlaySession $session): bool
    {
        return SessionListPlayer::query()->where('play_session_id', $session->id)->exists();
    }

    /**
     * Active listed players matching name. Fewer than 2 characters
     * returns nothing. `status` is the player's state in this session, null
     * when not checked in (or left), meaning they can check in.
     *
     * @return list<SearchRow>
     *
     * @throws ValidationException
     */
    public function search(PlaySession $session, string $token, string $query, string $ip): array
    {
        $this->throttle("selfcheckin:search:{$ip}:{$session->id}", self::SEARCH_PER_MINUTE, 60, 'Too many searches. Please wait a minute and try again.');

        $fresh = PlaySession::query()->whereKey($session->id)->first();
        if ($fresh === null) {
            throw ValidationException::withMessages(['session' => 'Check-in is closed for this session.']);
        }
        $this->assertUsable($fresh, $token);

        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';

        $players = Player::query()
            ->where('club_id', $session->club_id)
            ->where('active', true)
            ->whereIn('id', SessionListPlayer::query()->select('player_id')->where('play_session_id', $session->id))
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        /** @var array<int, SessionPlayerStatus> $statuses */
        $statuses = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->whereIn('player_id', $players->modelKeys())
            ->get(['player_id', 'status'])
            ->mapWithKeys(fn (SessionPlayer $e): array => [$e->player_id => $e->status])
            ->all();

        $rows = [];
        foreach ($players as $p) {
            $status = $statuses[$p->id] ?? null;
            $rows[] = [
                'id' => (string) $p->public_id,
                'name' => $p->name,
                // Only whether the player may still be asked; the gender itself is never exposed.
                'needs_gender' => $p->gender === null,
                'status' => match ($status) {
                    SessionPlayerStatus::Waiting => 'waiting',
                    SessionPlayerStatus::Playing => 'playing',
                    SessionPlayerStatus::Break => 'break',
                    default => null,
                },
            ];
        }

        return $rows;
    }

    /**
     * Check a listed club player in (or return them from break). The password, when the session
     * has one, is compared inside the session lock (trimmed, case-insensitive); a wrong or missing
     * one is a `password` error and, like every submit, counts toward the submit limit. A gender
     * is stored only when the player has none (never overwritten). Already being checked in is a
     * result, not an error. result: checked_in, returned_from_break or already_checked_in.
     *
     * @return CheckInResult
     *
     * @throws ValidationException
     */
    public function checkIn(PlaySession $session, string $token, string $playerPublicId, string $ip, ?string $gender = null, ?string $password = null): array
    {
        $this->throttle("selfcheckin:submit:{$ip}:{$session->id}", self::SUBMITS_PER_MINUTE, 60, 'Too many attempts. Please wait a minute and try again.');

        $gender = $this->parseGender($gender);
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $token, $playerPublicId, $gender, $password): array {
            $this->lockSession($session);
            $this->assertUsable($session, $token);

            // Before any player lookup, so the list can't be probed without the password.
            if (! $session->checkinPasswordMatches($password)) {
                throw ValidationException::withMessages([
                    'password' => trim((string) $password) === '' ? 'Enter the session password.' : "That password isn't right.",
                ]);
            }

            // Locking reads only until CheckInService::checkIn has run (see the race-safety
            // note there): no plain read may create the REPEATABLE READ snapshot first.
            $player = Player::query()
                ->where('club_id', $session->club_id)
                ->where('active', true)
                ->where('public_id', $playerPublicId)
                ->lockForUpdate()
                ->first();

            $listed = $player === null ? null : SessionListPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first(['id']);

            if ($player === null || $listed === null) {
                throw ValidationException::withMessages(['player' => 'Could not find that player. Search for your name again.']);
            }

            $existing = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first();

            $result = match ($existing?->status) {
                SessionPlayerStatus::Waiting, SessionPlayerStatus::Playing => 'already_checked_in',
                SessionPlayerStatus::Break => 'returned_from_break',
                default => 'checked_in',
            };

            // Saved on the player row this transaction already locks, before check-in, so the
            // refill inside checkIn() sees it. An UPDATE takes no read snapshot, so the
            // race-safety rule above still holds.
            $genderSet = $gender !== null && $player->gender === null;
            if ($genderSet) {
                $player->forceFill(['gender' => $gender])->save();
            }

            $this->checkIns->checkIn($session, $player, checkExpiry: false);

            // checkIn() returns early for a player already in, so refill and notify once here.
            if ($genderSet && $result === 'already_checked_in') {
                $this->checkIns->setGender($session, $player, $gender);
            }

            return [
                'result' => $result,
                'player_id' => (string) $player->public_id,
                'public_name' => $player->name,
            ];
        });
    }

    /**
     * Blank is no answer (null). Man, woman, m, w, male, female and f are accepted in any case.
     *
     * @throws ValidationException
     */
    private function parseGender(?string $gender): ?Gender
    {
        if ($gender === null || trim($gender) === '') {
            return null;
        }

        return Gender::tryParse($gender)
            ?? throw ValidationException::withMessages(['gender' => 'Please choose man or woman.']);
    }

    /**
     * Session must be draft or live and the token must still be the session's
     * current one. Reads the given instance, so call it on a locked session
     * (or a fresh one for read-only paths).
     *
     * @throws ValidationException
     */
    private function assertUsable(PlaySession $session, string $token): void
    {
        if (! in_array($session->status, [SessionStatus::Draft, SessionStatus::Live], true)) {
            throw ValidationException::withMessages(['session' => 'Check-in is closed for this session.']);
        }

        if ($session->checkin_token === null || ! hash_equals($session->checkin_token, $token)) {
            throw ValidationException::withMessages(['session' => self::STALE]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function throttle(string $key, int $max, int $decaySeconds, string $message): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['throttle' => $message]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
