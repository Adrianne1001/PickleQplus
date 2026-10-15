<?php

namespace App\Services;

use App\Enums\ListSource;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A session's player list (Phase 16). Staff build the list first (search, quick-add, import);
 * listed players with no session_players row are "Not Yet Here" until checked in. Every write
 * runs in one transaction that locks the play_sessions row first and fires PlaySessionChanged
 * after commit. Callers authorize (session `manage`, player `create` for new players).
 *
 * Club scoping: a player id is only ever resolved inside the session's own club, so an id from
 * another club is refused on every path.
 *
 * Error keys: `players` (add: missing, foreign, deactivated, guest cap), `player` (remove, set
 * skill, check in), `stars`, `password`, `session` (ended or expired), and the quick-add field keys
 * (`name`, `stars`, `dupr_id`, `gender`).
 *
 * @phpstan-type Skipped array{name: string, reason: string}
 * @phpstan-type CheckInAllResult array{checked_in: int, skipped: list<Skipped>}
 * @phpstan-type ListCounts array{checked_in: int, listed: int, not_yet_here: int}
 */
class SessionListService
{
    use LocksPlaySession;

    public const PASSWORD_MIN = 4;

    public const PASSWORD_MAX = 32;

    public function __construct(private readonly CheckInService $checkIns) {}

    /**
     * Add existing active club players to the list. Players already listed are a no-op.
     * Draft or live sessions only.
     *
     * @param  list<int|string>  $playerIds
     * @return int how many were newly listed
     *
     * @throws ValidationException
     */
    public function add(PlaySession $session, array $playerIds, ?User $actor = null, ListSource $source = ListSource::Manual): int
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $ids, $actor, $source): int {
            $this->lockSession($session);
            $this->guardOpen($session);

            $added = $this->addLocked($session, $ids, $actor?->id, $source);
            if ($added > 0) {
                PlaySessionChanged::dispatch($session->id);
            }

            return $added;
        });
    }

    /**
     * Quick-add: create a manual-rating club player and list them, in one transaction. A blank
     * stars value lists the player at 3 stars with "skill not set" (stars_pending).
     * Draft or live sessions only.
     *
     * @param  array{name?: mixed, stars?: mixed, dupr_id?: mixed, gender?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function addNewPlayer(PlaySession $session, array $data, ?User $actor = null, ListSource $source = ListSource::Manual): Player
    {
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $data, $actor, $source): Player {
            // Lock order club -> session, as PlaySessionService::start().
            $club = Club::query()->whereKey($session->club_id)->lockForUpdate()->firstOrFail();
            $this->lockSession($session);
            $this->guardOpen($session);

            $player = $this->checkIns->createQuickPlayer($club, $data);
            $this->addLocked($session, [$player->id], $actor?->id, $source);
            PlaySessionChanged::dispatch($session->id);

            return $player;
        });
    }

    /**
     * List players inside a transaction that already holds the session lock (and, when it also
     * creates players, the club lock). Entries are either a player id or `['id' => int, 'source' => string]`.
     *
     * @param  list<int|array{id: int, source?: string}>  $picks
     * @return int how many were newly listed
     *
     * @throws ValidationException
     */
    public function addLocked(PlaySession $session, array $picks, ?int $actorId, ListSource $default = ListSource::Manual): int
    {
        $sources = [];
        foreach ($picks as $pick) {
            if (is_array($pick)) {
                $sources[(int) $pick['id']] ??= ListSource::fromInput($pick['source'] ?? $default->value);
            } else {
                $sources[(int) $pick] ??= $default;
            }
        }
        $ids = array_keys($sources);
        if ($ids === []) {
            return 0;
        }
        sort($ids);

        $found = Player::query()
            ->where('club_id', $session->club_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $player = $found->get($id);
            if ($player === null) {
                // A foreign club's player is indistinguishable from a missing one, and is never named.
                throw ValidationException::withMessages(['players' => 'One of the selected players could not be found in this club.']);
            }
            if (! $player->active) {
                throw ValidationException::withMessages(['players' => "{$player->name} is deactivated and cannot be added. Reactivate them on the Players page first."]);
            }
        }

        // A plain read: every list write holds the session lock (which we hold), and a range lock on the
        // list could deadlock against an insert into the neighbouring session's range.
        $listed = SessionListPlayer::query()
            ->where('play_session_id', $session->id)
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $new = array_values(array_diff($ids, $listed));
        if ($new === []) {
            return 0;
        }

        $this->guardGuestListed($session, count($listed) + count($new));

        $now = Carbon::now();
        SessionListPlayer::query()->insertOrIgnore(array_map(fn (int $id): array => [
            'play_session_id' => $session->id,
            'player_id' => $id,
            'source' => $sources[$id]->value,
            'added_by_user_id' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $new));

        return count($new);
    }

    /**
     * Take a player off the list. Allowed only while they have no session_players row (a checked-in
     * player, including one who has left, stays listed).
     *
     * @throws ValidationException
     */
    public function remove(PlaySession $session, int $playerId): void
    {
        $this->guardGuestActive($session);

        DB::transaction(function () use ($session, $playerId): void {
            $this->lockSession($session);
            $this->guardOpen($session);

            // Lock order: session, player, list entry, session entry.
            $player = $this->lockedClubPlayer($session, $playerId);
            $entry = SessionListPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                throw ValidationException::withMessages(['player' => 'That player is not on the list.']);
            }

            $checkedIn = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->exists();

            if ($checkedIn) {
                throw ValidationException::withMessages(['player' => "{$player->name} is checked in. Remove their check-in first."]);
            }

            $entry->delete();
            PlaySessionChanged::dispatch($session->id);
        });
    }

    /**
     * Set the skill of a listed (or checked-in) player whose skill is still pending: stars 1 to 6,
     * clears stars_pending. rating_source stays manual.
     *
     * @throws ValidationException
     */
    public function setSkill(PlaySession $session, int $playerId, int|string $stars): Player
    {
        if (is_string($stars) && ! ctype_digit($stars)) {
            throw ValidationException::withMessages(['stars' => 'Choose 1 to 6 stars.']);
        }
        $stars = (int) $stars;
        if ($stars < 1 || $stars > 6) {
            throw ValidationException::withMessages(['stars' => 'Choose 1 to 6 stars.']);
        }

        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $playerId, $stars): Player {
            $this->lockSession($session);
            $this->guardOpen($session);

            $player = $this->lockedClubPlayer($session, $playerId);

            $listed = SessionListPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->exists();
            if (! $listed) {
                throw ValidationException::withMessages(['player' => 'That player is not on the list.']);
            }
            if (! $player->stars_pending) {
                throw ValidationException::withMessages(['player' => "{$player->name}'s skill is already set."]);
            }

            $player->forceFill(['stars' => $stars, 'stars_pending' => false])->save();
            PlaySessionChanged::dispatch($session->id);

            return $player;
        });
    }

    /**
     * Check one listed player in, through CheckInService (late-arrival credit, one live session
     * per player, guest caps).
     *
     * @throws ValidationException
     */
    public function checkIn(PlaySession $session, int $playerId): SessionPlayer
    {
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $playerId): SessionPlayer {
            $this->lockSession($session);
            $this->guardOpen($session);

            // Locking reads only until CheckInService::checkIn has run (see the race-safety note there).
            $player = $this->lockedClubPlayer($session, $playerId);
            $listed = SessionListPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first(['id']);
            if ($listed === null) {
                throw ValidationException::withMessages(['player' => 'That player is not on the list.']);
            }

            return $this->checkIns->checkIn($session, $player, checkExpiry: false);
        });
    }

    /**
     * Check in every Not Yet Here player, best effort, in one transaction under the session lock.
     * Players that CheckInService refuses are skipped with its reason.
     *
     * @return CheckInAllResult
     *
     * @throws ValidationException
     */
    public function checkInAll(PlaySession $session): array
    {
        $this->guardGuestActive($session);

        // Locking reads over the list and entries take range locks, which can in rare cases deadlock
        // against a check-in into a neighbouring session, so the whole batch is retried.
        return DB::transaction(function () use ($session): array {
            $this->lockSession($session);
            $this->guardOpen($session);

            // Every read until the check-ins start is a locking read, and every player row is locked up
            // front, so CheckInService's live-session clash check stays race-safe for the whole batch.
            $listed = SessionListPlayer::query()
                ->where('play_session_id', $session->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('player_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $here = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->lockForUpdate()
                ->pluck('player_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $todo = array_values(array_diff($listed, $here));
            if ($todo === []) {
                return ['checked_in' => 0, 'skipped' => []];
            }

            $players = Player::query()
                ->where('club_id', $session->club_id)
                ->whereIn('id', $todo)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $checkedIn = 0;
            $skipped = [];
            foreach ($todo as $id) {
                $player = $players->get($id);
                if ($player === null) {
                    continue;
                }

                try {
                    $this->checkIns->checkIn($session, $player, checkExpiry: false);
                    $checkedIn++;
                } catch (ValidationException $e) {
                    $reason = collect($e->errors())->flatten()->first();
                    $skipped[] = ['name' => $player->name, 'reason' => is_string($reason) ? $reason : 'Could not be checked in.'];
                }
            }

            PlaySessionChanged::dispatch($session->id);

            return ['checked_in' => $checkedIn, 'skipped' => $skipped];
        }, 3);
    }

    /**
     * Listed players with no session_players row, in the order they were listed.
     *
     * @return Collection<int, SessionListPlayer> with `player` loaded
     */
    public function notYetHere(PlaySession $session): Collection
    {
        return SessionListPlayer::query()
            ->where('play_session_id', $session->id)
            ->whereNotIn('player_id', SessionPlayer::query()->select('player_id')->where('play_session_id', $session->id))
            ->with('player')
            ->orderBy('id')
            ->get();
    }

    /**
     * "N/M checked in": N is the listed players whose status isn't `left`, M the list size.
     *
     * @return ListCounts
     */
    public function counts(PlaySession $session): array
    {
        $listedIds = SessionListPlayer::query()->select('player_id')->where('play_session_id', $session->id);

        $listed = SessionListPlayer::query()->where('play_session_id', $session->id)->count();
        $checkedIn = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->whereIn('player_id', $listedIds)
            ->count();
        $withEntry = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->whereIn('player_id', $listedIds)
            ->count();

        return ['checked_in' => $checkedIn, 'listed' => $listed, 'not_yet_here' => $listed - $withEntry];
    }

    /**
     * Ids of every listed player, for "already listed" checks in search results.
     *
     * @return list<int>
     */
    public function listedIds(PlaySession $session): array
    {
        return array_values(SessionListPlayer::query()
            ->where('play_session_id', $session->id)
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Active club players whose name contains $term, leaving out anyone already listed (and any
     * extra ids, e.g. pending picks in a form). Club-scoped through the session.
     *
     * @param  list<int>  $excludeIds
     * @return Collection<int, Player>
     */
    public function search(PlaySession $session, string $term, array $excludeIds = [], int $limit = 8): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return new Collection;
        }

        return Player::query()
            ->where('club_id', $session->club_id)
            ->where('active', true)
            ->where('name', 'like', '%'.addcslashes($term, '\\%_').'%')
            ->whereNotIn('id', SessionListPlayer::query()->select('player_id')->where('play_session_id', $session->id))
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Set, change or clear (null or blank) the session's check-in password. Trimmed, 4 to 32
     * characters. Doesn't affect anyone already checked in. Draft or live sessions only.
     *
     * @throws ValidationException
     */
    public function setPassword(PlaySession $session, ?string $password): PlaySession
    {
        $value = self::cleanPassword($password);
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $value): PlaySession {
            $this->lockSession($session);
            $this->guardOpen($session);

            if ($this->applyPassword($session, $value)) {
                PlaySessionChanged::dispatch($session->id);
            }

            return $session;
        });
    }

    public function clearPassword(PlaySession $session): PlaySession
    {
        return $this->setPassword($session, null);
    }

    /**
     * Trim and validate a password: null for blank, a ValidationException (key `password`) when it
     * isn't 4 to 32 characters.
     *
     * @throws ValidationException
     */
    public static function cleanPassword(?string $password): ?string
    {
        $value = trim((string) $password);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            throw ValidationException::withMessages(['password' => 'The password must be '.self::PASSWORD_MIN.' to '.self::PASSWORD_MAX.' characters.']);
        }

        return $value;
    }

    /**
     * Store an already cleaned password on a session this transaction holds locked.
     *
     * @return bool whether it changed
     */
    public function applyPassword(PlaySession $session, ?string $cleaned): bool
    {
        if ($session->checkin_password === $cleaned) {
            return false;
        }

        $session->forceFill(['checkin_password' => $cleaned])->save();

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function lockedClubPlayer(PlaySession $session, int $playerId): Player
    {
        $player = Player::query()
            ->where('club_id', $session->club_id)
            ->whereKey($playerId)
            ->lockForUpdate()
            ->first();

        if ($player === null) {
            throw ValidationException::withMessages(['player' => 'That player is no longer available. Search again.']);
        }

        return $player;
    }

    /**
     * @throws ValidationException
     */
    private function guardGuestListed(PlaySession $session, int $total): void
    {
        if (! Club::query()->whereKey($session->club_id)->value('is_guest')) {
            return;
        }

        $max = (int) config('pickleq.guest.max_listed');
        if ($total > $max) {
            throw ValidationException::withMessages(['players' => "A guest session can list at most {$max} players. Sign up for more."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardOpen(PlaySession $session): void
    {
        if ($session->status === SessionStatus::Ended) {
            throw ValidationException::withMessages(['session' => 'This session has ended.']);
        }
    }
}
