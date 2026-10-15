<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\ListSource;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The session form's save: create or update the session, create the quick-added players and put
 * the picks on the session's player list (Phase 16), all in one transaction (all or nothing).
 * Nobody is checked in here: staff check players in from the board.
 */
class SessionFormService
{
    use LocksPlaySession;

    public function __construct(
        private readonly PlaySessionService $sessions,
        private readonly SessionListService $list,
        private readonly CheckInService $checkIns,
        private readonly PlayerService $players,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The session settings, as PlaySessionService::create/update take them. An optional
     *                                      `checkin_password` key sets, changes or (blank) clears the session password; absent keeps it.
     * @param  list<int|string|array{id: int|string, source?: string, gender?: mixed}>  $playerIds  Existing club players to list. An import pick may carry a
     *                                                                                              `gender` (man or woman, any alias): set on the player when they have none, never overwritten.
     * @param  list<array{name?: mixed, stars?: mixed, dupr_id?: mixed, gender?: mixed, dupr_rating?: mixed, source?: string}>  $newPlayers  Players to create, then list. Blank stars and no rating: 3 stars, skill not set.
     *
     * Error keys: the session setting keys, `password`, `status` (ended session), `players` (a pick that is
     * missing or has an invalid gender, from another club, inactive, or over the guest list cap), `new_players.{i}.name|stars|dupr_id|gender|dupr_rating`
     * (i is the index in $newPlayers).
     *
     * @throws ValidationException
     */
    public function save(Club $club, ?PlaySession $session, array $data, array $playerIds = [], array $newPlayers = [], ?User $actor = null): PlaySession
    {
        $setPassword = array_key_exists('checkin_password', $data);
        $password = $setPassword ? SessionListService::cleanPassword(is_string($data['checkin_password']) ? $data['checkin_password'] : null) : null;
        unset($data['checkin_password']);

        /** @var array<int, int|array{id: int, source: string}> $byId */
        $byId = [];
        /** @var array<int, Gender> $genderFills */
        $genderFills = [];
        foreach ($playerIds as $pick) {
            if (is_array($pick)) {
                $id = (int) $pick['id'];
                $byId[$id] ??= ['id' => $id, 'source' => ListSource::fromInput($pick['source'] ?? null)->value];
                $gender = $this->pickGender($pick['gender'] ?? null);
                if ($gender !== null) {
                    $genderFills[$id] ??= $gender;
                }
            } else {
                $byId[(int) $pick] ??= (int) $pick;
            }
        }
        $picks = array_values($byId);

        $this->guardDuplicateNames($newPlayers);

        return DB::transaction(function () use ($club, $session, $data, $picks, $genderFills, $newPlayers, $setPassword, $password, $actor): PlaySession {
            // Lock order club -> session -> players, as PlaySessionService::start().
            $this->guardClubNotExpired(Club::query()->whereKey($club->id)->lockForUpdate()->firstOrFail());

            if ($session !== null) {
                if ($session->club_id !== $club->id) {
                    throw ValidationException::withMessages(['session' => 'That session does not belong to this club.']);
                }
                $this->lockSession($session);
                if ($session->status === SessionStatus::Ended) {
                    throw ValidationException::withMessages(['status' => 'An ended session cannot be changed.']);
                }
            }

            $session = $session === null
                ? $this->sessions->create($club, $data)
                : $this->sessions->update($session, $data);

            if ($setPassword) {
                $this->list->applyPassword($session, $password);
            }

            $this->fillGenders($club, $genderFills);

            $created = [];
            foreach ($newPlayers as $i => $row) {
                $player = $this->checkIns->createQuickPlayer($club, $row, "new_players.{$i}.");
                $created[] = ['id' => $player->id, 'source' => ListSource::fromInput($row['source'] ?? null)->value];
            }

            $this->list->addLocked($session, [...$picks, ...$created], $actor?->id);

            return $session;
        });
    }

    /**
     * A pick's gender: null when blank, the parsed gender otherwise. Anything else (it comes from component
     * state, which a client can forge) is rejected, never written.
     *
     * @throws ValidationException
     */
    private function pickGender(mixed $raw): ?Gender
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        return Gender::tryParse($raw)
            ?? throw ValidationException::withMessages(['players' => 'Gender must be man or woman.']);
    }

    /**
     * Set the gender of club players who have none, on the locked rows: a gender set since the review stays.
     *
     * @param  array<int, Gender>  $fills  player id => gender
     */
    private function fillGenders(Club $club, array $fills): void
    {
        if ($fills === []) {
            return;
        }

        $locked = Player::query()
            ->where('club_id', $club->id)
            ->whereIn('id', array_keys($fills))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($locked as $player) {
            if ($player->gender === null && $player->active) {
                $this->players->update($player, ['gender' => $fills[$player->id]]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $newPlayers
     *
     * @throws ValidationException
     */
    private function guardDuplicateNames(array $newPlayers): void
    {
        $seen = [];
        foreach ($newPlayers as $i => $row) {
            $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
            if ($name === '') {
                continue; // reported by validation as required
            }
            if (isset($seen[$name])) {
                throw ValidationException::withMessages([
                    "new_players.{$i}.name" => 'You already added a new player with that name.',
                ]);
            }
            $seen[$name] = true;
        }
    }
}
