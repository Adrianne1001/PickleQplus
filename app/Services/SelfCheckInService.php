<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Rules\DuprPlayerId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Public self check-in (reached through the venue QR token). Limits are
 * enforced here, keyed by IP and session, because the UI is a Livewire
 * component whose actions skip route middleware. Every method re-checks the
 * token against the session (inside the session lock when it writes), so a
 * page opened with a replaced QR stops working. Players are identified
 * publicly by players.public_id, never by their sequential id.
 *
 * @phpstan-type SearchRow array{id: string, name: string, nickname: string|null, needs_gender: bool, status: 'waiting'|'playing'|'break'|null}
 * @phpstan-type CheckInResult array{result: string, player_id: string, public_name: string}
 */
class SelfCheckInService
{
    use LocksPlaySession;

    public const MIN_QUERY = 2;

    public const MAX_RESULTS = 10;

    public const SEARCH_PER_MINUTE = 60;

    public const SUBMITS_PER_MINUTE = 10;

    public const REGISTRATIONS_PER_HOUR = 5;

    public const REGISTRATIONS_PER_SESSION = 100;

    private const STALE = 'This QR code has been replaced. Scan the new one.';

    public function __construct(private readonly CheckInService $checkIns) {}

    /**
     * Active club players matching name or nickname. Fewer than 2 characters
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
            ->where(fn ($q) => $q
                ->whereRaw("lower(name) like ? escape '!'", [$like])
                ->orWhereRaw("lower(nickname) like ? escape '!'", [$like]))
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
                'nickname' => $p->nickname,
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
     * Check an existing club player in (or return them from break). A nickname
     * and a gender are stored only when the player has none (never overwritten). Already being checked in is a
     * result, not an error. result: checked_in, returned_from_break or
     * already_checked_in.
     *
     * @return CheckInResult
     *
     * @throws ValidationException
     */
    public function checkIn(PlaySession $session, string $token, string $playerPublicId, ?string $nickname, string $ip, ?string $gender = null): array
    {
        $this->throttle("selfcheckin:submit:{$ip}:{$session->id}", self::SUBMITS_PER_MINUTE, 60, 'Too many attempts. Please wait a minute and try again.');

        $gender = $this->parseGender($gender);
        $nickname = Player::normalizeNickname($nickname);
        if ($nickname !== null && mb_strlen($nickname) > 20) {
            throw ValidationException::withMessages(['nickname' => 'The nickname may not be longer than 20 characters.']);
        }

        try {
            return DB::transaction(function () use ($session, $token, $playerPublicId, $nickname, $gender): array {
                $this->lockSession($session);
                $this->assertUsable($session, $token);

                // Locking reads only until CheckInService::checkIn has run (see the race-safety
                // note there): no plain read may create the REPEATABLE READ snapshot first.
                $player = Player::query()
                    ->where('club_id', $session->club_id)
                    ->where('active', true)
                    ->where('public_id', $playerPublicId)
                    ->lockForUpdate()
                    ->first();

                if ($player === null) {
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

                $this->checkIns->checkIn($session, $player);

                if ($nickname !== null && $player->nickname === null) {
                    if (Player::nicknameTaken($session->club_id, $nickname, $player->id)) {
                        throw ValidationException::withMessages(['nickname' => 'That nickname is already taken. Please pick another.']);
                    }
                    $player->forceFill(['nickname' => $nickname])->save();
                    if ($result === 'already_checked_in') {
                        PlaySessionChanged::dispatch($session->id);
                    }
                }

                // checkIn() returns early for a player already in, so refill and notify once here.
                if ($genderSet && $result === 'already_checked_in') {
                    $this->checkIns->setGender($session, $player, $gender);
                }

                return [
                    'result' => $result,
                    'player_id' => (string) $player->public_id,
                    'public_name' => $player->publicName(),
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nickname' => 'That nickname is already taken. Please pick another.']);
        }
    }

    /**
     * Create a manual, self-registered player and check them in.
     *
     * @return CheckInResult result is registered
     *
     * @throws ValidationException
     */
    public function register(PlaySession $session, string $token, string $name, string $nickname, ?string $duprId, int|string $stars, string $ip, ?string $gender = null): array
    {
        $this->throttle("selfcheckin:submit:{$ip}:{$session->id}", self::SUBMITS_PER_MINUTE, 60, 'Too many attempts. Please wait a minute and try again.');

        $registerKey = "selfcheckin:register:{$ip}";
        if (RateLimiter::tooManyAttempts($registerKey, self::REGISTRATIONS_PER_HOUR)) {
            throw ValidationException::withMessages(['throttle' => 'Too many new registrations from this device. Please ask the organizer for help.']);
        }

        $gender = $this->parseGender($gender);

        $data = Validator::make([
            'name' => trim($name),
            'nickname' => Player::normalizeNickname($nickname),
            'dupr_id' => DuprPlayerId::normalize($duprId),
            'stars' => $stars,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['required', 'string', 'max:20'],
            'dupr_id' => ['nullable', 'string', new DuprPlayerId],
            'stars' => ['required', 'integer', 'between:1,6'],
        ])->validate();

        $onRoster = 'You are already on the roster. Search for your name instead.';

        try {
            $result = DB::transaction(function () use ($session, $token, $data, $gender, $onRoster): array {
                // Same lock order as PlaySessionService::start(): club, session, then players.
                Club::query()->whereKey($session->club_id)->lockForUpdate()->firstOrFail();
                $this->lockSession($session);
                $this->assertUsable($session, $token);

                // A brand-new player cannot be in another live session, so the race that
                // CheckInService guards against does not apply. The club lock serialises
                // registrations; the unique indexes and the catch below cover the rest.
                $registered = SessionPlayer::query()
                    ->where('play_session_id', $session->id)
                    ->selfRegisteredHere()
                    ->count();
                if ($registered >= self::REGISTRATIONS_PER_SESSION) {
                    throw ValidationException::withMessages(['register' => 'Self-registration is full for this session. Ask the organizer to add you.']);
                }

                if (Player::nicknameTaken($session->club_id, (string) $data['nickname'])) {
                    throw ValidationException::withMessages(['nickname' => $onRoster]);
                }
                if ($data['dupr_id'] !== null
                    && Player::query()->where('club_id', $session->club_id)->where('dupr_id', $data['dupr_id'])->exists()) {
                    throw ValidationException::withMessages(['dupr_id' => $onRoster]);
                }

                $player = new Player(['active' => true]);
                $player->club_id = $session->club_id;
                $player->forceFill([
                    'name' => $data['name'],
                    'nickname' => $data['nickname'],
                    'gender' => $gender,
                    'dupr_id' => $data['dupr_id'],
                    'dupr_rating' => null,
                    'rating_source' => RatingSource::Manual,
                    'stars' => (int) $data['stars'],
                    'self_registered_at' => Carbon::now(),
                    'self_registered_session_id' => $session->id,
                ])->save();

                $this->checkIns->checkIn($session, $player);

                return ['result' => 'registered', 'player_id' => (string) $player->public_id, 'public_name' => $player->publicName()];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nickname' => $onRoster]);
        }

        RateLimiter::hit($registerKey, 3600);

        return $result;
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
