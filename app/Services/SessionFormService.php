<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The session form's save: create or update the session, create the quick-added
 * players and check in the picks, all in one transaction (all or nothing).
 */
class SessionFormService
{
    use LocksPlaySession;

    public function __construct(
        private readonly PlaySessionService $sessions,
        private readonly CheckInService $checkIns,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The session settings, as PlaySessionService::create/update take them.
     * @param  list<int|string>  $playerIds  Existing club players to check in.
     * @param  list<array{name?: mixed, stars?: mixed, dupr_id?: mixed, gender?: mixed}>  $newPlayers  Players to create, then check in.
     *
     * Error keys: the session setting keys, `status` (ended session), `players` (a pick that is
     * missing, from another club, inactive, or refused by check-in; names the player where known),
     * `new_players.{i}.name|stars|dupr_id|gender` (i is the index in $newPlayers).
     *
     * @throws ValidationException
     */
    public function save(Club $club, ?PlaySession $session, array $data, array $playerIds = [], array $newPlayers = []): PlaySession
    {
        $ids = array_values(array_unique(array_map('intval', $playerIds)));

        $this->guardDuplicateNames($newPlayers);

        return DB::transaction(function () use ($club, $session, $data, $ids, $newPlayers): PlaySession {
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

            // Locking reads before any plain read keep CheckInService's live-session clash check correct.
            $picks = $this->resolvePicks($club, $ids);

            $session = $session === null
                ? $this->sessions->create($club, $data)
                : $this->sessions->update($session, $data);

            foreach ($picks as $player) {
                try {
                    $this->checkIns->checkIn($session, $player, checkExpiry: false);
                } catch (ValidationException $e) {
                    $first = collect($e->errors())->flatten()->first();
                    throw ValidationException::withMessages(['players' => "{$player->name}: {$first}"]);
                }
            }

            foreach ($newPlayers as $i => $row) {
                $player = $this->checkIns->createQuickPlayer($club, $row, "new_players.{$i}.");
                try {
                    $this->checkIns->checkIn($session, $player, checkExpiry: false);
                } catch (ValidationException $e) {
                    $first = collect($e->errors())->flatten()->first();
                    throw ValidationException::withMessages(['players' => "{$player->name}: {$first}"]);
                }
            }

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

    /**
     * @param  list<int>  $ids
     * @return list<Player>
     *
     * @throws ValidationException
     */
    private function resolvePicks(Club $club, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = Player::query()
            ->where('club_id', $club->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $picks = [];
        foreach ($ids as $id) {
            $player = $found->get($id);
            if ($player === null || $player->club_id !== $club->id) {
                // A foreign club's player is indistinguishable from a missing one, and is never named.
                throw ValidationException::withMessages(['players' => 'One of the selected players could not be found in this club.']);
            }
            if (! $player->active) {
                throw ValidationException::withMessages(['players' => "{$player->name} is deactivated and cannot be checked in."]);
            }
            $picks[] = $player;
        }

        return $picks;
    }
}
