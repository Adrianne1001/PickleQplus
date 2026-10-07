<?php

namespace App\Services;

use App\Domain\Rotation\SkillGroups;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\Rotation\RotationQueries;
use App\Services\Rotation\SkillCourtsStrategy;
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

    public const WINNERS_STAY_MIN = 1;

    public const WINNERS_STAY_MAX = 5;

    /** @var array{type: string, games: int, to: int, win_by: int} */
    public const DEFAULT_SCORING = ['type' => 'side_out', 'games' => 1, 'to' => 11, 'win_by' => 2];

    /**
     * Create a draft session. Missing settings take the club defaults.
     *
     * @param  array{name?: string|null, date?: mixed, courts?: int|null, up_next_count?: int|null, auto_fill?: bool|null, scoring?: array<string, mixed>|null, rotation_mode?: RotationMode|string|null, mode_settings?: array<string, mixed>|null}  $data
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
            'rotation_mode' => RotationMode::Balanced->value,
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
     * Partial update of name, date, courts, up_next_count, auto_fill, scoring,
     * rotation_mode and mode_settings. Allowed while draft or live. A court that
     * has a playing match can't be removed. A change that voids Up Next on a live
     * session (see changeVoidsUpNext()) voids the staged matches (playing matches
     * carry on) and refills under the new mode.
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

            $merged = array_replace($session->only(['name', 'date', 'courts', 'up_next_count', 'auto_fill', 'scoring']), ['rotation_mode' => $session->rotation_mode->value], $data);
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

            $voids = $session->isLive() && $this->changeVoidsUpNext($session, $values);
            $session->fill($values)->save();

            if ($voids) {
                (new RotationQueries($session))->voidAllStaged();
            }

            $this->matches->refill($session);
            PlaySessionChanged::dispatch($session->id);

            return $session;
        });
    }

    /**
     * Would applying this update input void the staged Up Next matches of a live
     * session? True when the rotation mode changes, or when the groups a skill courts
     * session stages from change: an edit of skill_groups, or a courts change that
     * alters any group's range. Anything else, such as winners_stay_max_wins, takes
     * effect at the next finish. Always false for a draft or ended session. The input
     * is the same partial array update() takes.
     *
     * @param  array<string, mixed>  $input
     */
    public function changeVoidsUpNext(PlaySession $session, array $input): bool
    {
        if (! $session->isLive()) {
            return false;
        }

        $mode = array_key_exists('rotation_mode', $input)
            ? ($input['rotation_mode'] instanceof RotationMode ? $input['rotation_mode'] : RotationMode::tryFrom((string) $input['rotation_mode']))
            : $session->rotation_mode;
        if ($mode !== $session->rotation_mode) {
            return true;
        }

        if ($mode !== RotationMode::SkillCourts) {
            return false;
        }

        $courts = isset($input['courts']) && is_numeric($input['courts']) ? (int) $input['courts'] : $session->courts;
        $given = is_array($input['mode_settings'] ?? null) && is_array($input['mode_settings']['skill_groups'] ?? null)
            ? $input['mode_settings']['skill_groups']
            : null;

        try {
            $new = $this->resolveSkillGroups($session, $given, $courts)->toArray();
        } catch (ValidationException) {
            return false; // update() rejects it before anything is voided
        }
        $old = SkillCourtsStrategy::groupsFor($session)?->toArray() ?? [];

        return $new !== $old;
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
     * The groups a skill courts session will have after an update. Given groups are
     * validated as they are. Without them, a session already in skill courts keeps its
     * groups with the last one resized to the new court count, and a session switching
     * to skill courts (or one with no usable groups) gets the defaults.
     *
     * @param  array<array-key, mixed>|null  $given
     *
     * @throws ValidationException
     */
    private function resolveSkillGroups(?PlaySession $existing, ?array $given, int $courts): SkillGroups
    {
        if ($courts < SkillGroups::MIN_COURTS) {
            throw ValidationException::withMessages(['courts' => 'Skill courts needs at least 2 courts.']);
        }

        if ($given !== null) {
            try {
                return SkillGroups::fromArray($given, $courts);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['mode_settings.skill_groups' => $e->getMessage()]);
            }
        }

        $current = $existing?->rotation_mode === RotationMode::SkillCourts
            ? SkillGroups::tryFromArray($existing->mode_settings['skill_groups'], $existing->courts)
            : null;
        if ($current === null) {
            return SkillGroups::defaultFor($courts);
        }

        try {
            return $current->resizedTo($courts);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['courts' => 'That court count would leave a skill group with no courts. Edit the groups first.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, date: string, courts: int, up_next_count: int, auto_fill: bool, scoring: array{type: string, games: int, to: int, win_by: int}, rotation_mode: string, mode_settings?: array{winners_stay_max_wins: int, skill_groups: list<array<string, int>>}}
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
            // Only modes in config('pickleq.rotation_modes_enabled') can be chosen yet.
            'rotation_mode' => ['required', Rule::in(array_unique([...array_map(fn (RotationMode $m): string => $m->value, RotationMode::selectable()), ...($existing === null ? [] : [$existing->rotation_mode->value])]))],
            'mode_settings' => ['sometimes', 'nullable', 'array'],
            'mode_settings.winners_stay_max_wins' => ['sometimes', 'nullable', 'integer', 'between:'.self::WINNERS_STAY_MIN.','.self::WINNERS_STAY_MAX],
            'mode_settings.skill_groups' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        /** @var array<string, mixed> $scoring */
        $scoring = $validated['scoring'];

        $result = [
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
            'rotation_mode' => (string) $validated['rotation_mode'],
        ];

        $mode = RotationMode::from((string) $validated['rotation_mode']);
        /** @var array<string, mixed>|null $settings */
        $settings = array_key_exists('mode_settings', $validated) ? (array) $validated['mode_settings'] : null;

        if ($settings !== null || $mode === RotationMode::SkillCourts) {
            $stored = $existing?->mode_settings;
            $given = is_array($settings['skill_groups'] ?? null) ? $settings['skill_groups'] : null;

            $result['mode_settings'] = [
                'winners_stay_max_wins' => (int) ($settings['winners_stay_max_wins'] ?? $stored['winners_stay_max_wins'] ?? config('pickleq.rotation.winners_stay_max_wins', 2)),
                // Only skill courts uses the groups; every other mode keeps whatever is stored.
                'skill_groups' => $mode === RotationMode::SkillCourts
                    ? $this->resolveSkillGroups($existing, $given, $result['courts'])->toArray()
                    : ($stored['skill_groups'] ?? []),
            ];
        }

        return $result;
    }
}
