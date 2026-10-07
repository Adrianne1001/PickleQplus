<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\LateArrivalPolicy;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Manual check-in, check-out, break and return for a session. Every method
 * runs in one transaction that locks the play_sessions row first, and fires
 * PlaySessionChanged after commit.
 */
class CheckInService
{
    use LocksPlaySession;

    public function __construct(private readonly MatchService $matches) {}

    /**
     * Check an active club player in (draft or live). A player already in the
     * session and not `left` is left untouched; a `left` player is re-checked
     * in; a player on `break` returns from break.
     *
     * @throws ValidationException
     */
    public function checkIn(PlaySession $session, Player $player): SessionPlayer
    {
        return DB::transaction(function () use ($session, $player): SessionPlayer {
            $this->lockSession($session);
            $this->guardOpen($session);

            if ($player->club_id !== $session->club_id) {
                throw ValidationException::withMessages(['player' => 'That player does not belong to this club.']);
            }

            // Lock the player so concurrent check-ins into two live sessions can't both pass.
            $fresh = Player::query()->whereKey($player->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->active) {
                throw ValidationException::withMessages(['player' => 'Inactive players cannot be checked in.']);
            }

            $entry = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('player_id', $player->id)
                ->lockForUpdate()
                ->first();

            if ($entry !== null && $entry->status !== SessionPlayerStatus::Left && $entry->status !== SessionPlayerStatus::Break) {
                return $entry;
            }

            // Race safety against PlaySessionService::start(): this check is only correct
            // because every read before it (session, player, entry) is a locking read, so no
            // REPEATABLE READ snapshot exists yet and the plain read below sees committed rows.
            // Keep any non-locking read out of this method above this line.
            if ($session->isLive()) {
                $this->guardOtherLiveSession($session, $player);
            }

            if ($entry === null) {
                $entry = new SessionPlayer(['play_session_id' => $session->id, 'player_id' => $player->id]);
                $entry->games_played = 0;
                $entry->games_credit = 0;
                $entry->checked_in_at = Carbon::now();
            }

            $this->enterQueue($session, $entry);
            PlaySessionChanged::dispatch($session->id);

            return $entry;
        });
    }

    /**
     * Set (or clear, with null) a player's gender from the board. Staff are the
     * authority, so this overwrites an existing value. Runs in the session lock and
     * refills, so a mode that was waiting for this player can stage a match at once.
     * Allowed while the session is draft or live.
     *
     * @throws ValidationException
     */
    public function setGender(PlaySession $session, Player $player, Gender|string|null $gender): Player
    {
        $parsed = Gender::tryParse($gender);
        if ($parsed === null && $gender !== null && (! is_string($gender) || trim($gender) !== '')) {
            throw ValidationException::withMessages(['gender' => 'Gender must be man or woman.']);
        }

        return DB::transaction(function () use ($session, $player, $parsed): Player {
            $this->lockSession($session);
            $this->guardOpen($session);

            if ($player->club_id !== $session->club_id) {
                throw ValidationException::withMessages(['player' => 'That player does not belong to this club.']);
            }

            $fresh = Player::query()->whereKey($player->id)->where('club_id', $session->club_id)->lockForUpdate()->firstOrFail();
            $changed = $fresh->gender !== $parsed;
            $fresh->forceFill(['gender' => $parsed])->save();
            $player->setRawAttributes($fresh->getAttributes(), true);

            if ($changed && $session->isLive() && $session->rotation_mode === RotationMode::Mixed) {
                // A staged match built on the old gender may no longer be 1 man + 1 woman per team.
                $this->voidStagedWith($session, $player);
            }

            $this->matches->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $player;
        });
    }

    /**
     * A player's gender was changed outside the board (the staff player form).
     * In every live mixed session the player is checked into, void their staged
     * match, refill and notify, exactly like setGender. Runs under the session lock.
     */
    public function gendersChanged(Player $player): void
    {
        $sessions = PlaySession::query()
            ->where('club_id', $player->club_id)
            ->where('status', SessionStatus::Live->value)
            ->where('rotation_mode', RotationMode::Mixed->value)
            ->whereIn('id', SessionPlayer::query()->select('play_session_id')->where('player_id', $player->id))
            ->get();

        foreach ($sessions as $session) {
            DB::transaction(function () use ($session, $player): void {
                $this->lockSession($session);
                if (! $session->isLive() || $session->rotation_mode !== RotationMode::Mixed) {
                    return;
                }
                $this->voidStagedWith($session, $player);
                $this->matches->refill($session);
                PlaySessionChanged::dispatch($session->id);
            });
        }
    }

    private function voidStagedWith(PlaySession $session, Player $player): void
    {
        GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->whereIn('id', MatchPlayer::query()->select('match_id')->where('player_id', $player->id))
            ->update(['status' => MatchStatus::Void->value, 'updated_at' => now()]);
    }

    /**
     * Staff: remove a bogus check-in. The session_players row is deleted only
     * when the player has no staged, playing or done match in this session
     * (otherwise use check-out). A player who self-registered in this session
     * (players.self_registered_session_id), with no match anywhere and no
     * other session entries, is deleted too (spam record, no history).
     *
     * @throws ValidationException
     */
    public function removeCheckIn(PlaySession $session, Player $player, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', $session);

        DB::transaction(function () use ($session, $player): void {
            $this->lockSession($session);
            $this->guardOpen($session);

            // Lock order: session, then player, then entry, before any plain read.
            Player::query()->whereKey($player->id)->lockForUpdate()->first();
            $entry = $this->lockedEntry($session, $player);

            $hasMatch = GameMatch::query()
                ->where('play_session_id', $session->id)
                ->whereIn('status', [MatchStatus::Staged->value, MatchStatus::Playing->value, MatchStatus::Done->value])
                ->whereHas('matchPlayers', fn ($q) => $q->where('player_id', $player->id))
                ->exists();

            if ($hasMatch) {
                throw ValidationException::withMessages([
                    'player' => 'This player has matches in this session. Use check-out instead.',
                ]);
            }

            $spam = SessionPlayer::query()->selfRegisteredHere()->whereKey($entry->id)->exists()
                && ! MatchPlayer::query()->where('player_id', $player->id)->exists()
                && SessionPlayer::query()->where('player_id', $player->id)->whereKeyNot($entry->id)->doesntExist();

            $entry->delete();

            if ($spam) {
                Player::query()->whereKey($player->id)->delete();
            }

            $this->matches->refill($session);
            PlaySessionChanged::dispatch($session->id);
        });
    }

    /**
     * Check a player out for the session (status `left`).
     *
     * @throws ValidationException when the player is playing.
     */
    public function checkOut(PlaySession $session, Player $player): SessionPlayer
    {
        return $this->leaveQueue($session, $player, SessionPlayerStatus::Left);
    }

    /**
     * Put a player on break.
     *
     * @throws ValidationException when the player is playing or has left.
     */
    public function goOnBreak(PlaySession $session, Player $player): SessionPlayer
    {
        return $this->leaveQueue($session, $player, SessionPlayerStatus::Break);
    }

    /**
     * Bring a player back from break. Restarts the wait clock; when the
     * session is live the club's late arrival policy may add games credit.
     *
     * @throws ValidationException
     */
    public function returnFromBreak(PlaySession $session, Player $player): SessionPlayer
    {
        return DB::transaction(function () use ($session, $player): SessionPlayer {
            $this->lockSession($session);
            $this->guardOpen($session);

            $entry = $this->lockedEntry($session, $player);
            if ($entry->status !== SessionPlayerStatus::Break) {
                throw ValidationException::withMessages(['player' => 'That player is not on break.']);
            }

            $this->enterQueue($session, $entry);
            PlaySessionChanged::dispatch($session->id);

            return $entry;
        });
    }

    /**
     * Games credit for a player entering the queue of a live session, per the
     * club's late arrival policy. Credit only ever raises: the result is never
     * below $existingCredit.
     *
     * - minimum: up to the lowest effective_games of the other waiting and playing players
     * - front: no credit
     * - back: up to the highest effective_games among them
     * - nobody else active: no new credit
     */
    public function creditFor(PlaySession $session, SessionPlayer $entry, LateArrivalPolicy $policy): int
    {
        $existing = $entry->games_credit;

        if ($policy === LateArrivalPolicy::Front) {
            return $existing;
        }

        $bounds = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('player_id', '!=', $entry->player_id)
            ->whereIn('status', [SessionPlayerStatus::Waiting->value, SessionPlayerStatus::Playing->value])
            ->selectRaw('COUNT(*) as others, MIN(games_played + games_credit) as lowest, MAX(games_played + games_credit) as highest')
            ->toBase()
            ->first();

        if ($bounds === null || (int) $bounds->others === 0) {
            return $existing;
        }

        $target = (int) ($policy === LateArrivalPolicy::Back ? $bounds->highest : $bounds->lowest);

        return max($existing, $target - $entry->games_played);
    }

    /**
     * Called after staged matches were voided because one of their players
     * left or went on break: re-stage the freed slots (refill is idempotent).
     */
    protected function restageAfterVoid(PlaySession $session): void
    {
        $this->matches->refill($session);
    }

    /**
     * @throws ValidationException
     */
    private function leaveQueue(PlaySession $session, Player $player, SessionPlayerStatus $to): SessionPlayer
    {
        return DB::transaction(function () use ($session, $player, $to): SessionPlayer {
            $this->lockSession($session);
            $this->guardOpen($session);

            $entry = $this->lockedEntry($session, $player);

            if ($entry->status === SessionPlayerStatus::Playing) {
                throw ValidationException::withMessages([
                    'player' => 'This player is in a match in progress. Swap them out first.',
                ]);
            }

            if ($to === SessionPlayerStatus::Break && $entry->status === SessionPlayerStatus::Left) {
                throw ValidationException::withMessages(['player' => 'This player has left the session.']);
            }

            $voided = GameMatch::query()
                ->where('play_session_id', $session->id)
                ->where('status', MatchStatus::Staged->value)
                ->whereHas('matchPlayers', fn ($q) => $q->where('player_id', $player->id))
                ->get();

            foreach ($voided as $match) {
                $match->status = MatchStatus::Void;
                $match->save();
            }

            $entry->status = $to;
            $entry->save();

            $this->restageAfterVoid($session);

            PlaySessionChanged::dispatch($session->id);

            return $entry;
        });
    }

    /**
     * Mark the entry waiting, restart its wait clock and (when live) apply credit.
     */
    private function enterQueue(PlaySession $session, SessionPlayer $entry): void
    {
        if ($session->isLive()) {
            $club = Club::query()->findOrFail($session->club_id);
            $entry->games_credit = $this->creditFor($session, $entry, $club->late_arrival_policy);
        }

        $entry->status = SessionPlayerStatus::Waiting;
        $entry->queued_at = Carbon::now();
        $entry->save();

        $this->matches->refill($session);
    }

    /**
     * @throws ValidationException
     */
    private function lockedEntry(PlaySession $session, Player $player): SessionPlayer
    {
        $entry = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('player_id', $player->id)
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['player' => 'That player is not checked in to this session.']);
        }

        return $entry;
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

    /**
     * A player can be in only one live session at a time.
     *
     * @throws ValidationException
     */
    private function guardOtherLiveSession(PlaySession $session, Player $player): void
    {
        $elsewhere = SessionPlayer::query()
            ->where('player_id', $player->id)
            ->where('play_session_id', '!=', $session->id)
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->whereHas('playSession', fn ($q) => $q->where('status', SessionStatus::Live->value))
            ->exists();

        if ($elsewhere) {
            throw ValidationException::withMessages(['player' => 'This player is already in another live session.']);
        }
    }
}
