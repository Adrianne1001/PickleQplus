<?php

namespace App\Services;

use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;

/**
 * Read model for the public queue page and the TV. Exposes only public names
 * (nickname, else first name + last initial) and player ids: never full names,
 * stars, DUPR data or match ids. Board ordering and wait estimates come from
 * SessionBoard.
 *
 * @phpstan-type PublicPlayer array{id: string, name: string}
 * @phpstan-type PublicMatch array{teams: array{A: list<PublicPlayer>, B: list<PublicPlayer>}, player_ids: list<string>, elapsed_minutes: int|null}
 * @phpstan-type PublicSnapshot array{
 *     name: string,
 *     status: string,
 *     courts: list<array{court: int, match: PublicMatch|null}>,
 *     up_next: list<PublicMatch>,
 *     waiting: list<array{position: int, id: string, name: string, estimate_minutes: int|null}>,
 *     on_break: list<PublicPlayer>,
 *     players: list<PublicPlayer>
 * }
 */
class PublicSessionView
{
    public function __construct(private readonly SessionBoard $board) {}

    /**
     * Courts, matches and queues are filled only while the session is live;
     * draft and ended sessions return empty lists (the page shows a message).
     * `players` is every checked-in player (not left), for "This is me".
     *
     * @return PublicSnapshot
     */
    public function snapshot(PlaySession $session): array
    {
        $name = $session->name;
        $status = $session->status->value;

        if ($session->status !== SessionStatus::Live) {
            return ['name' => $name, 'status' => $status, 'courts' => [], 'up_next' => [], 'waiting' => [], 'on_break' => [], 'players' => []];
        }

        $names = [];
        $roster = Player::query()
            ->whereIn('id', SessionPlayer::query()->select('player_id')->where('play_session_id', $session->id))
            ->get(['id', 'public_id', 'name', 'nickname']);
        $pub = [];
        foreach ($roster as $player) {
            $names[$player->id] = $player->publicName();
            $pub[$player->id] = (string) $player->public_id;
        }
        $publicName = fn (int $id): string => $names[$id] ?? '';
        $publicId = fn (int $id): string => $pub[$id] ?? '';

        /** @param  array{teams: array{A: list<array{id: int}>, B: list<array{id: int}>}, player_ids: list<int>, elapsed_minutes: int|null}  $row */
        $match = function (array $row) use ($publicName, $publicId): array {
            $teams = ['A' => [], 'B' => []];
            foreach (['A', 'B'] as $team) {
                foreach ($row['teams'][$team] as $p) {
                    $teams[$team][] = ['id' => $publicId($p['id']), 'name' => $publicName($p['id'])];
                }
            }

            return [
                'teams' => $teams,
                'player_ids' => array_values(array_map($publicId, $row['player_ids'])),
                'elapsed_minutes' => $row['elapsed_minutes'],
            ];
        };

        $courts = [];
        foreach ($this->board->courts($session) as $c) {
            $courts[] = ['court' => $c['court'], 'match' => $c['match'] === null ? null : $match($c['match'])];
        }

        $waiting = [];
        foreach ($this->board->waiting($session) as $i => $row) {
            $waiting[] = ['position' => $i + 1, 'id' => $publicId($row['id']), 'name' => $publicName($row['id']), 'estimate_minutes' => $row['estimate_minutes']];
        }

        $onBreak = [];
        foreach ($this->board->onBreak($session) as $row) {
            $onBreak[] = ['id' => $publicId($row['id']), 'name' => $publicName($row['id'])];
        }

        $players = [];
        $ids = SessionPlayer::query()
            ->where('play_session_id', $session->id)
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->pluck('player_id');
        foreach ($ids as $id) {
            $players[] = ['id' => $publicId((int) $id), 'name' => $publicName((int) $id)];
        }
        usort($players, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

        return [
            'name' => $name,
            'status' => $status,
            'courts' => $courts,
            'up_next' => array_map($match, $this->board->staged($session)),
            'waiting' => $waiting,
            'on_break' => $onBreak,
            'players' => $players,
        ];
    }
}
