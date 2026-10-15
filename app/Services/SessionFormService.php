<?php

namespace App\Services;

use App\Enums\ListSource;
use App\Enums\SessionStatus;
use App\Models\Club;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data  The session settings, as PlaySessionService::create/update take them. An optional
     *                                      `checkin_password` key sets, changes or (blank) clears the session password; absent keeps it.
     * @param  list<int|string|array{id: int|string, source?: string}>  $playerIds  Existing club players to list.
     * @param  list<array{name?: mixed, stars?: mixed, dupr_id?: mixed, gender?: mixed, dupr_rating?: mixed, source?: string}>  $newPlayers  Players to create, then list. Blank stars and no rating: 3 stars, skill not set.
     *
     * Error keys: the session setting keys, `password`, `status` (ended session), `players` (a pick that is
     * missing, from another club, inactive, or over the guest list cap), `new_players.{i}.name|stars|dupr_id|gender|dupr_rating`
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
        foreach ($playerIds as $pick) {
            if (is_array($pick)) {
                $id = (int) $pick['id'];
                $byId[$id] ??= ['id' => $id, 'source' => ListSource::fromInput($pick['source'] ?? null)->value];
            } else {
                $byId[(int) $pick] ??= (int) $pick;
            }
        }
        $picks = array_values($byId);

        $this->guardDuplicateNames($newPlayers);

        return DB::transaction(function () use ($club, $session, $data, $picks, $newPlayers, $setPassword, $password, $actor): PlaySession {
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
