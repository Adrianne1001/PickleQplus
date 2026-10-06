<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Play session lifecycle (draft -> live -> ended) and settings. Every state
 * change locks the play_sessions row first and fires PlaySessionChanged after
 * commit.
 */
class PlaySessionService
{
    use LocksPlaySession;

    public function __construct(private readonly MatchService $matches) {}

    public const COURTS_MIN = 1;

    public const COURTS_MAX = 50;

    public const SCORING_TO = [11, 15, 21];

    public const SCORING_WIN_BY = [1, 2];

    /** @var array{type: string, games: int, to: int, win_by: int} */
    public const DEFAULT_SCORING = ['type' => 'side_out', 'games' => 1, 'to' => 11, 'win_by' => 2];

    /**
     * Create a draft session. Missing settings take the club defaults.
     *
     * @param  array{name?: string|null, date?: mixed, courts?: int|null, up_next_count?: int|null, auto_fill?: bool|null, scoring?: array<string, mixed>|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Club $club, array $data = []): PlaySession
    {
        $given = array_filter($data, fn ($v): bool => $v !== null);
        if (isset($given['scoring'])) {
            $given['scoring'] = array_replace(self::DEFAULT_SCORING, $given['scoring']);
        }

        $values = $this->validated($given + [
            'name' => 'Open Play',
            'date' => now()->toDateString(),
            'courts' => $club->default_courts,
            'up_next_count' => 1,
            'auto_fill' => false,
            'scoring' => self::DEFAULT_SCORING,
        ], null);

        $session = new PlaySession($values);
        $session->status = SessionStatus::Draft;
        $session->club()->associate($club);

        return DB::transaction(function () use ($session): PlaySession {
            $session->save();
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * Partial update of name, date, courts, up_next_count, auto_fill and
     * scoring. Allowed while draft or live. A court that has a playing match
     * can't be removed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(PlaySession $session, array $data): PlaySession
    {
        return DB::transaction(function () use ($session, $data): PlaySession {
            $this->lockSession($session);
            $this->guardNotEnded($session);

            $merged = array_replace($session->only(['name', 'date', 'courts', 'up_next_count', 'auto_fill', 'scoring']), $data);
            $merged['date'] = $merged['date'] instanceof \DateTimeInterface ? $merged['date']->format('Y-m-d') : $merged['date'];
            if (isset($data['scoring']) && is_array($data['scoring'])) {
                $merged['scoring'] = array_replace($session->scoring, $data['scoring']);
            }

            $values = $this->validated($merged, $session);

            if ($values['courts'] < $session->courts) {
                $blocked = $session->matches()
                    ->where('status', MatchStatus::Playing->value)
                    ->where('court_no', '>', $values['courts'])
                    ->exists();

                if ($blocked) {
                    throw ValidationException::withMessages([
                        'courts' => 'A court with a match in progress cannot be removed. Finish or void that match first.',
                    ]);
                }
            }

            $session->fill($values)->save();
            $this->matches->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * Go live. Locks the club row; unless the club allows concurrent
     * sessions, no other session in the club may be live.
     *
     * @throws ValidationException
     */
    public function start(PlaySession $session): PlaySession
    {
        return DB::transaction(function () use ($session): PlaySession {
            $club = Club::query()->whereKey($session->club_id)->lockForUpdate()->firstOrFail();
            $this->lockSession($session);

            // Lock this session's checked-in players in one statement BEFORE any plain read.
            // InnoDB REPEATABLE READ takes its snapshot at the first non-locking read, and a
            // concurrent checkIn() into another live session holds the player row lock until it
            // commits, so the clash query below then sees its committed row. Lock order is
            // club -> session -> players, consistent with checkIn() (session -> player).
            Player::query()
                ->whereIn('id', SessionPlayer::query()
                    ->select('player_id')
                    ->where('play_session_id', $session->id)
                    ->where('status', '!=', SessionPlayerStatus::Left->value))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($session->status !== SessionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft session can be started.']);
            }

            $otherLive = PlaySession::query()
                ->where('club_id', $club->id)
                ->where('status', SessionStatus::Live->value)
                ->whereKeyNot($session->id);

            if (! $club->allow_concurrent_sessions && $otherLive->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Another session is already live. End it first, or ask an owner to allow concurrent sessions.',
                ]);
            }

            // A player may only be in one live session at a time.
            $clash = SessionPlayer::query()
                ->where('play_session_id', $session->id)
                ->where('status', '!=', SessionPlayerStatus::Left->value)
                ->whereIn('player_id', SessionPlayer::query()
                    ->select('player_id')
                    ->where('status', '!=', SessionPlayerStatus::Left->value)
                    ->whereIn('play_session_id', $otherLive->clone()->select('id')))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'status' => 'Some checked-in players are already in another live session.',
                ]);
            }

            $session->status = SessionStatus::Live;
            $session->started_at = Carbon::now();
            $session->save();
            $this->matches->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * End a live session. Blocked while a match is playing; staged matches
     * are voided.
     *
     * @throws ValidationException
     */
    public function end(PlaySession $session): PlaySession
    {
        return DB::transaction(function () use ($session): PlaySession {
            $this->lockSession($session);

            if ($session->status !== SessionStatus::Live) {
                throw ValidationException::withMessages(['status' => 'Only a live session can be ended.']);
            }

            if ($session->matches()->where('status', MatchStatus::Playing->value)->exists()) {
                throw ValidationException::withMessages(['status' => 'Finish or void the matches in progress before ending the session.']);
            }

            $session->matches()
                ->where('status', MatchStatus::Staged->value)
                ->update(['status' => MatchStatus::Void->value, 'updated_at' => now()]);

            $session->status = SessionStatus::Ended;
            $session->ended_at = Carbon::now();
            $session->checkin_token = null;
            $session->save();
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * Replace the check-in token; the old QR stops working at once. Staff only.
     *
     * @throws ValidationException
     */
    public function regenerateCheckinToken(PlaySession $session, User $actor): PlaySession
    {
        Gate::forUser($actor)->authorize('manage', $session);

        return DB::transaction(function () use ($session): PlaySession {
            $this->lockSession($session);
            $this->guardNotEnded($session);

            $session->checkin_token = PlaySession::newCheckinToken();
            $session->save();
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * Replace the secret TV link id; the old TV URL stops working at once.
     * Staff only; allowed in any status.
     */
    public function resetTvLink(PlaySession $session, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage', $session);

        DB::transaction(function () use ($session): void {
            $this->lockSession($session);

            $session->tv_id = Str::random(32);
            $session->save();
            PlaySessionChanged::dispatch($session->id);
        });
    }

    /**
     * Delete a draft session (with its check-ins).
     *
     * @throws ValidationException
     */
    public function delete(PlaySession $session): void
    {
        DB::transaction(function () use ($session): void {
            $this->lockSession($session);

            if ($session->status !== SessionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Only a draft session can be deleted.']);
            }

            $session->delete();
            PlaySessionChanged::dispatch($session->id);
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardNotEnded(PlaySession $session): void
    {
        if ($session->status === SessionStatus::Ended) {
            throw ValidationException::withMessages(['status' => 'An ended session cannot be changed.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, date: string, courts: int, up_next_count: int, auto_fill: bool, scoring: array{type: string, games: int, to: int, win_by: int}}
     *
     * @throws ValidationException
     */
    private function validated(array $data, ?PlaySession $existing): array
    {
        $data['date'] = $data['date'] instanceof \DateTimeInterface ? $data['date']->format('Y-m-d') : $data['date'];

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date_format:Y-m-d'],
            'courts' => ['required', 'integer', 'between:'.self::COURTS_MIN.','.self::COURTS_MAX],
            'up_next_count' => ['required', 'integer', 'between:1,3'],
            'auto_fill' => ['required', 'boolean'],
            'scoring' => ['required', 'array'],
            'scoring.type' => ['required', Rule::in(['side_out'])],
            'scoring.games' => ['required', Rule::in([1])],
            'scoring.to' => ['required', Rule::in(self::SCORING_TO)],
            'scoring.win_by' => ['required', Rule::in(self::SCORING_WIN_BY)],
        ])->validate();

        /** @var array<string, mixed> $scoring */
        $scoring = $validated['scoring'];

        return [
            'name' => trim((string) $validated['name']),
            'date' => (string) $validated['date'],
            'courts' => (int) $validated['courts'],
            'up_next_count' => (int) $validated['up_next_count'],
            'auto_fill' => (bool) $validated['auto_fill'],
            'scoring' => [
                'type' => 'side_out',
                'games' => 1,
                'to' => (int) $scoring['to'],
                'win_by' => (int) $scoring['win_by'],
            ],
        ];
    }
}
