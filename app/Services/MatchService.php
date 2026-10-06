<?php

namespace App\Services;

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\MatchResult;
use App\Domain\Rotation\PairHistory;
use App\Domain\Rotation\RotationEngine;
use App\Domain\Rotation\Weights;
use App\Domain\Scoring\ScoreValidator;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Events\PlaySessionChanged;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Up Next staging, starting, finishing, swapping and voiding matches.
 *
 * Every public board method runs in one transaction that locks the
 * play_sessions row first and fires PlaySessionChanged after commit. Each
 * takes the session plus the match and rejects a match from another session,
 * so matches and players are always resolved inside the session (and club)
 * the caller is already authorised for.
 *
 * While the session is live, empty Up Next slots are always refilled at the end
 * of every state change (see refill()). `auto_fill` only decides whether free
 * courts start the oldest staged match automatically.
 */
class MatchService
{
    use LocksPlaySession;

    /**
     * Start a staged match on a court (lowest free court when none is given).
     *
     * @throws ValidationException
     */
    public function startMatch(PlaySession $session, GameMatch $match, ?int $courtNo = null): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session, $courtNo): void {
            $this->requireStatus($match, MatchStatus::Staged, 'Only a staged match can be started.');

            $ids = $this->playerIds($match);
            $waiting = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->whereIn('player_id', $ids)
                ->where('status', SessionPlayerStatus::Waiting->value)
                ->count();
            if ($waiting !== count($ids)) {
                throw ValidationException::withMessages(['match' => 'Every player in the match must be waiting.']);
            }

            if ($courtNo === null) {
                $courtNo = $this->lowestFreeCourt($session);
                if ($courtNo === null) {
                    throw ValidationException::withMessages(['court' => 'No court is free.']);
                }
            } else {
                if ($courtNo < 1 || $courtNo > $session->courts) {
                    throw ValidationException::withMessages(['court' => 'That court does not exist in this session.']);
                }
                if (in_array($courtNo, $this->busyCourts($session), true)) {
                    throw ValidationException::withMessages(['court' => 'That court is in use.']);
                }
            }

            $this->doStart($session, $match, $courtNo);
        });
    }

    /**
     * Record the score of a playing match: players return to waiting with a
     * game counted and a fresh wait clock, and the court is freed.
     *
     * @throws ValidationException
     */
    public function finish(PlaySession $session, GameMatch $match, int $teamA, int $teamB): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session, $teamA, $teamB): void {
            $this->requireStatus($match, MatchStatus::Playing, 'Only a match in progress can be finished.');
            $this->guardScore($session, $teamA, $teamB);

            $now = Carbon::now();
            $match->team_a_score = $teamA;
            $match->team_b_score = $teamB;
            $match->status = MatchStatus::Done;
            $match->finished_at = $now;
            $match->save();

            foreach ($this->entries($session, $this->playerIds($match)) as $entry) {
                $entry->games_played++;
                $entry->last_finished_at = $now;
                $entry->queued_at = $now;
                if ($entry->status === SessionPlayerStatus::Playing) {
                    $entry->status = SessionPlayerStatus::Waiting;
                }
                $entry->save();
            }
        });
    }

    /**
     * Revert the session's most recent done match to playing. Allowed only
     * when its court is free and none of its players is in a staged or
     * playing match or has left. Otherwise use editScore().
     *
     * @throws ValidationException
     */
    public function undoLast(PlaySession $session): GameMatch
    {
        return DB::transaction(function () use ($session): GameMatch {
            $this->lockSession($session);
            $this->guardLive($session);

            $match = GameMatch::query()
                ->where('play_session_id', $session->id)
                ->where('status', MatchStatus::Done->value)
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($match === null) {
                throw ValidationException::withMessages(['match' => 'There is no finished match to undo.']);
            }
            $this->guardExported($match);

            $ids = $this->playerIds($match);
            $entries = $this->entries($session, $ids);

            $courtFree = $match->court_no !== null
                && $match->court_no <= $session->courts
                && ! in_array($match->court_no, $this->busyCourts($session), true);
            $playing = MatchPlayer::query()
                ->whereIn('match_id', GameMatch::query()
                    ->select('id')
                    ->where('play_session_id', $session->id)
                    ->where('status', MatchStatus::Playing->value))
                ->whereIn('player_id', $ids)
                ->exists();
            $left = $entries->contains(fn (SessionPlayer $e): bool => in_array($e->status, [SessionPlayerStatus::Left, SessionPlayerStatus::Break], true));

            if (! $courtFree || $playing || $left) {
                throw ValidationException::withMessages([
                    'match' => 'This result cannot be undone because the court or its players are in use. Edit the score instead.',
                ]);
            }

            // Players re-staged since the match finished are pulled out of Up Next.
            $restaged = GameMatch::query()
                ->where('play_session_id', $session->id)
                ->where('status', MatchStatus::Staged->value)
                ->whereHas('matchPlayers', fn ($q) => $q->whereIn('player_id', $ids))
                ->lockForUpdate()
                ->get();
            foreach ($restaged as $staged) {
                $this->markVoid($staged);
            }

            $match->team_a_score = null;
            $match->team_b_score = null;
            $match->status = MatchStatus::Playing;
            $match->finished_at = null;
            $match->save();

            foreach ($entries as $entry) {
                $previous = GameMatch::query()
                    ->where('play_session_id', $session->id)
                    ->where('status', MatchStatus::Done->value)
                    ->whereKeyNot($match->id)
                    ->whereHas('matchPlayers', fn ($q) => $q->where('player_id', $entry->player_id))
                    ->max('finished_at');
                $when = is_string($previous) ? Carbon::parse($previous) : null;

                $entry->games_played = max(0, $entry->games_played - 1);
                $entry->last_finished_at = $when;
                $entry->queued_at = $when ?? $entry->checked_in_at;
                $entry->status = SessionPlayerStatus::Playing;
                $entry->save();
            }

            $this->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $match;
        });
    }

    /**
     * Change the score of any done match that has not been DUPR-exported.
     *
     * @throws ValidationException
     */
    public function editScore(PlaySession $session, GameMatch $match, int $teamA, int $teamB): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session, $teamA, $teamB): void {
            $this->requireStatus($match, MatchStatus::Done, 'Only a finished match can be edited.');
            $this->guardExported($match);
            $this->guardScore($session, $teamA, $teamB);

            $match->team_a_score = $teamA;
            $match->team_b_score = $teamB;
            $match->save();
        }, liveOnly: false);
    }

    /**
     * Swap a player in a staged or playing match for a waiting player who is
     * not in another match. The player swapped out goes back to waiting and
     * keeps their place in the queue.
     *
     * @throws ValidationException
     */
    public function swap(PlaySession $session, GameMatch $match, Player $out, Player $in): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session, $out, $in): void {
            $this->requireOpenMatch($match);

            $row = $match->matchPlayers()->where('player_id', $out->id)->first();
            if ($row === null) {
                throw ValidationException::withMessages(['player' => 'That player is not in this match.']);
            }
            if ($out->id === $in->id) {
                throw ValidationException::withMessages(['player' => 'Choose a different player to swap in.']);
            }

            $this->requireAvailable($session, $in);

            $row->player_id = $in->id;
            $row->save();

            if ($match->status === MatchStatus::Playing) {
                $this->setStatus($session, [$out->id], SessionPlayerStatus::Waiting);
                $this->setStatus($session, [$in->id], SessionPlayerStatus::Playing);
            }
        });
    }

    /**
     * Take a player out of a staged or playing match and fill the slot with
     * the waiting player the engine ranks best. The removed player becomes
     * waiting, break or left. No game is counted for them.
     *
     * If nobody is available to fill the slot the removal is blocked with a
     * validation error and nothing changes.
     *
     * @throws ValidationException
     */
    public function remove(PlaySession $session, GameMatch $match, Player $player, SessionPlayerStatus $newStatus = SessionPlayerStatus::Waiting): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session, $player, $newStatus): void {
            $this->requireOpenMatch($match);

            if ($newStatus === SessionPlayerStatus::Playing) {
                throw ValidationException::withMessages(['status' => 'A removed player can only become waiting, on break or left.']);
            }

            $rows = $match->matchPlayers()->get();
            $row = $rows->firstWhere('player_id', $player->id);
            if ($row === null) {
                throw ValidationException::withMessages(['player' => 'That player is not in this match.']);
            }

            $entries = $this->entries($session, array_values($rows->pluck('player_id')->map(fn ($id): int => (int) $id)->all()))->keyBy('player_id');
            $teamA = [];
            $teamB = [];
            foreach ($rows as $other) {
                $entry = $entries->get($other->player_id);
                if ($other->player_id === $player->id || $entry === null) {
                    continue;
                }
                if ($other->team === Team::A) {
                    $teamA[] = $this->candidate($entry);
                } else {
                    $teamB[] = $this->candidate($entry);
                }
            }

            $result = $this->engine()->pickReplacement(
                $teamA,
                $teamB,
                $this->candidates($session),
                $this->history($session, $match->id),
            );

            if ($result === null) {
                throw ValidationException::withMessages(['player' => 'No waiting player is available to take that slot.']);
            }

            $row->player_id = $result->playerId;
            $row->save();

            $this->setStatus($session, [$player->id], $newStatus);
            if ($match->status === MatchStatus::Playing) {
                $this->setStatus($session, [$result->playerId], SessionPlayerStatus::Playing);
            }
        });
    }

    /**
     * Void a match. Staged: players are freed. Playing: players go back to
     * waiting with no game counted and the court is freed. Done: games_played
     * is decremented for its players. A DUPR-exported match cannot be voided.
     *
     * @throws ValidationException
     */
    public function void(PlaySession $session, GameMatch $match): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session): void {
            $this->guardExported($match);

            match ($match->status) {
                MatchStatus::Void => throw ValidationException::withMessages(['match' => 'This match is already void.']),
                MatchStatus::Playing => $this->voidPlaying($session, $match),
                MatchStatus::Done => $this->voidDone($session, $match),
                MatchStatus::Staged => $this->markVoid($match),
            };
        }, liveOnly: false);
    }

    /**
     * Void a staged match and stage a different one: the engine runs once per
     * old player with that player left out, and the cheapest result wins, so the
     * same four never come back unless fewer than 5 players are waiting.
     *
     * @throws ValidationException
     */
    public function reroll(PlaySession $session, GameMatch $match): GameMatch
    {
        return $this->change($session, $match, function (GameMatch $match) use ($session): void {
            $this->requireStatus($match, MatchStatus::Staged, 'Only a staged match can be re-rolled.');

            $ids = $this->playerIds($match);
            $this->markVoid($match);

            // Run the engine once with each old player left out and keep the cheapest result,
            // so the exact same four can never come back while a different group exists.
            // Only when every run finds nothing (fewer than 5 waiting) may refill() re-stage them.
            $candidates = $this->candidates($session);
            $history = $this->history($session);
            $best = null;
            foreach ($ids as $leaveOut) {
                $result = $this->engine()->pickMatch(
                    array_values(array_filter($candidates, fn (Candidate $c): bool => $c->id !== $leaveOut)),
                    $history,
                );
                if ($result !== null && ($best === null || $result->cost < $best->cost)) {
                    $best = $result;
                }
            }
            if ($best !== null) {
                $this->createStaged($session, $best);
            }
        });
    }

    /**
     * End-of-change step for a live session; a no-op otherwise. Call it inside
     * the caller's transaction after the session row is locked.
     *
     * 1. Void the newest staged matches beyond up_next_count.
     * 2. Fill empty Up Next slots from the waiting players.
     * 3. With auto_fill on, start the oldest staged match on each free court,
     *    refilling Up Next after each start.
     */
    public function refill(PlaySession $session): void
    {
        if (! $session->isLive()) {
            return;
        }

        $staged = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->orderByDesc('id')
            ->get();
        foreach ($staged->take(max(0, $staged->count() - $session->up_next_count)) as $surplus) {
            $this->markVoid($surplus);
        }

        do {
            while ($this->stagedCount($session) < $session->up_next_count && $this->stage($session) !== null) {
                // keep staging
            }

            $started = $session->auto_fill && $this->startOldest($session);
        } while ($started);
    }

    /**
     * Run a match change: lock the session, re-read the match inside it, run
     * the change, refill, and dispatch the event.
     *
     * @param  callable(GameMatch): void  $change
     *
     * @throws ValidationException
     */
    private function change(PlaySession $session, GameMatch $match, callable $change, bool $liveOnly = true): GameMatch
    {
        return DB::transaction(function () use ($session, $match, $change, $liveOnly): GameMatch {
            $this->lockSession($session);
            $liveOnly ? $this->guardLive($session) : $this->guardNotDraft($session);

            $locked = GameMatch::query()
                ->whereKey($match->id)
                ->where('play_session_id', $session->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                throw ValidationException::withMessages(['match' => 'That match does not belong to this session.']);
            }
            $match->setRawAttributes($locked->getAttributes(), true);

            $change($match);

            $this->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $match;
        });
    }

    /**
     * @param  list<int>  $exclude
     */
    private function stage(PlaySession $session, array $exclude = []): ?GameMatch
    {
        $result = $this->engine()->pickMatch($this->candidates($session, $exclude), $this->history($session));

        return $result === null ? null : $this->createStaged($session, $result);
    }

    private function createStaged(PlaySession $session, MatchResult $result): GameMatch
    {
        $match = new GameMatch(['dupr_eligible' => true]);
        $match->play_session_id = $session->id;
        $match->status = MatchStatus::Staged;
        $match->save();

        foreach ([[Team::A, $result->teamA], [Team::B, $result->teamB]] as [$team, $ids]) {
            foreach ($ids as $i => $playerId) {
                MatchPlayer::query()->create([
                    'match_id' => $match->id,
                    'player_id' => $playerId,
                    'team' => $team,
                    'slot' => $i + 1,
                ]);
            }
        }

        return $match;
    }

    private function startOldest(PlaySession $session): bool
    {
        $court = $this->lowestFreeCourt($session);
        $match = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->orderBy('id')
            ->first();

        if ($court === null || $match === null) {
            return false;
        }

        $this->doStart($session, $match, $court);

        return true;
    }

    private function doStart(PlaySession $session, GameMatch $match, int $court): void
    {
        $match->status = MatchStatus::Playing;
        $match->court_no = $court;
        $match->started_at = Carbon::now();
        $match->save();

        $this->setStatus($session, $this->playerIds($match), SessionPlayerStatus::Playing);
    }

    private function voidPlaying(PlaySession $session, GameMatch $match): void
    {
        $this->setStatus($session, $this->playerIds($match), SessionPlayerStatus::Waiting, SessionPlayerStatus::Playing);
        $match->court_no = null;
        $this->markVoid($match);
    }

    private function voidDone(PlaySession $session, GameMatch $match): void
    {
        foreach ($this->entries($session, $this->playerIds($match)) as $entry) {
            $entry->games_played = max(0, $entry->games_played - 1);
            $entry->save();
        }
        $this->markVoid($match);
    }

    private function markVoid(GameMatch $match): void
    {
        $match->status = MatchStatus::Void;
        $match->save();
    }

    /**
     * @param  list<int>  $playerIds
     */
    private function setStatus(PlaySession $session, array $playerIds, SessionPlayerStatus $to, ?SessionPlayerStatus $only = null): void
    {
        SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->whereIn('player_id', $playerIds)
            ->when($only !== null, fn ($q) => $q->where('status', $only?->value))
            ->update(['status' => $to->value, 'updated_at' => Carbon::now()]);
    }

    /**
     * @param  list<int>  $playerIds
     * @return Collection<int, SessionPlayer>
     */
    private function entries(PlaySession $session, array $playerIds): Collection
    {
        return SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->whereIn('player_id', $playerIds)
            ->get();
    }

    /**
     * @return list<int>
     */
    private function playerIds(GameMatch $match): array
    {
        return array_values(array_map('intval', $match->matchPlayers()->pluck('player_id')->all()));
    }

    /**
     * Players in a staged or playing match of the session.
     *
     * @return list<int>
     */
    private function occupiedPlayerIds(PlaySession $session): array
    {
        return array_values(array_map('intval', MatchPlayer::query()
            ->whereIn('match_id', GameMatch::query()
                ->select('id')
                ->where('play_session_id', $session->id)
                ->whereIn('status', [MatchStatus::Staged->value, MatchStatus::Playing->value]))
            ->pluck('player_id')
            ->all()));
    }

    /**
     * Waiting players who are not in a staged or playing match.
     *
     * @param  list<int>  $exclude
     * @return list<Candidate>
     */
    private function candidates(PlaySession $session, array $exclude = []): array
    {
        $skip = array_merge($this->occupiedPlayerIds($session), $exclude);

        return array_values(SessionPlayer::query()
            ->with('player')
            ->where('play_session_id', $session->id)
            ->where('status', SessionPlayerStatus::Waiting->value)
            ->whereNotIn('player_id', $skip)
            ->get()
            ->map(fn (SessionPlayer $entry): Candidate => $this->candidate($entry))
            ->all());
    }

    private function candidate(SessionPlayer $entry): Candidate
    {
        $queued = $entry->queued_at ?? $entry->checked_in_at;
        $player = $entry->player ?? throw new \LogicException('Session player has no player.');

        return new Candidate(
            $entry->player_id,
            $player->stars,
            $entry->effectiveGames(),
            $queued?->getTimestamp() ?? 0,
        );
    }

    /**
     * Partner and opponent counts over the session's non-void matches.
     */
    private function history(PlaySession $session, ?int $exceptMatchId = null): PairHistory
    {
        $matches = GameMatch::query()
            ->with('matchPlayers')
            ->where('play_session_id', $session->id)
            ->where('status', '!=', MatchStatus::Void->value)
            ->when($exceptMatchId !== null, fn ($q) => $q->whereKeyNot($exceptMatchId))
            ->get()
            ->map(fn (GameMatch $m): array => [
                $m->matchPlayers->where('team', Team::A)->pluck('player_id')->map(fn ($id): int => (int) $id)->values()->all(),
                $m->matchPlayers->where('team', Team::B)->pluck('player_id')->map(fn ($id): int => (int) $id)->values()->all(),
            ]);

        return PairHistory::fromMatches($matches);
    }

    private function engine(): RotationEngine
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('pickleq.rotation', []);

        return new BalancedRotationEngine(Weights::fromConfig($config));
    }

    private function stagedCount(PlaySession $session): int
    {
        return GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->count();
    }

    /**
     * @return list<int>
     */
    private function busyCourts(PlaySession $session): array
    {
        return array_values(array_map('intval', GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Playing->value)
            ->whereNotNull('court_no')
            ->pluck('court_no')
            ->all()));
    }

    private function lowestFreeCourt(PlaySession $session): ?int
    {
        $busy = $this->busyCourts($session);
        for ($court = 1; $court <= $session->courts; $court++) {
            if (! in_array($court, $busy, true)) {
                return $court;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function requireAvailable(PlaySession $session, Player $player): void
    {
        $entry = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('player_id', $player->id)
            ->first();

        if ($entry === null || $entry->status !== SessionPlayerStatus::Waiting
            || in_array($player->id, $this->occupiedPlayerIds($session), true)) {
            throw ValidationException::withMessages(['player' => 'That player is not waiting, or is already in another match.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireStatus(GameMatch $match, MatchStatus $status, string $message): void
    {
        if ($match->status !== $status) {
            throw ValidationException::withMessages(['match' => $message]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireOpenMatch(GameMatch $match): void
    {
        if ($match->status !== MatchStatus::Staged && $match->status !== MatchStatus::Playing) {
            throw ValidationException::withMessages(['match' => 'Only a staged or playing match can be changed.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardExported(GameMatch $match): void
    {
        if ($match->dupr_exported_at !== null) {
            throw ValidationException::withMessages(['match' => 'This match was already exported to DUPR.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardScore(PlaySession $session, int $teamA, int $teamB): void
    {
        $error = ScoreValidator::error($session->scoring, $teamA, $teamB);
        if ($error !== null) {
            throw ValidationException::withMessages(['score' => $error]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardLive(PlaySession $session): void
    {
        if (! $session->isLive()) {
            throw ValidationException::withMessages(['session' => 'The session is not live.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardNotDraft(PlaySession $session): void
    {
        if ($session->isDraft()) {
            throw ValidationException::withMessages(['session' => 'The session has not started.']);
        }
    }
}
