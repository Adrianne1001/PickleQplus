<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\MatchResult;
use App\Domain\Rotation\PairHistory;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\PlaySession;
use App\Models\SessionPlayer;

/**
 * Read and create helpers shared by MatchService and every rotation strategy,
 * all scoped to one session. Call inside the session lock.
 */
final class RotationQueries
{
    public function __construct(public readonly PlaySession $session) {}

    /**
     * Players in a staged or playing match of the session.
     *
     * @return list<int>
     */
    public function occupiedPlayerIds(): array
    {
        return array_values(array_map('intval', MatchPlayer::query()
            ->whereIn('match_id', GameMatch::query()
                ->select('id')
                ->where('play_session_id', $this->session->id)
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
    public function candidates(array $exclude = []): array
    {
        $skip = array_merge($this->occupiedPlayerIds(), $exclude);

        return array_values(SessionPlayer::query()
            ->with('player')
            ->where('play_session_id', $this->session->id)
            ->where('status', SessionPlayerStatus::Waiting->value)
            ->whereNotIn('player_id', $skip)
            ->get()
            ->map(fn (SessionPlayer $entry): Candidate => $this->candidate($entry))
            ->all());
    }

    public function candidate(SessionPlayer $entry): Candidate
    {
        $queued = $entry->queued_at ?? $entry->checked_in_at;
        $player = $entry->player ?? throw new \LogicException('Session player has no player.');

        return new Candidate(
            $entry->player_id,
            $player->stars,
            $entry->effectiveGames(),
            $queued?->getTimestamp() ?? 0,
            $player->gender,
        );
    }

    /**
     * Partner and opponent counts over the session's non-void matches.
     */
    public function history(?int $exceptMatchId = null): PairHistory
    {
        $matches = GameMatch::query()
            ->with('matchPlayers')
            ->where('play_session_id', $this->session->id)
            ->where('status', '!=', MatchStatus::Void->value)
            ->when($exceptMatchId !== null, fn ($q) => $q->whereKeyNot($exceptMatchId))
            ->get()
            ->map(fn (GameMatch $m): array => [
                $m->matchPlayers->where('team', Team::A)->pluck('player_id')->map(fn ($id): int => (int) $id)->values()->all(),
                $m->matchPlayers->where('team', Team::B)->pluck('player_id')->map(fn ($id): int => (int) $id)->values()->all(),
            ]);

        return PairHistory::fromMatches($matches);
    }

    public function stagedCount(?int $courtGroup = null): int
    {
        return GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->where('status', MatchStatus::Staged->value)
            ->when($courtGroup !== null, fn ($q) => $q->where('court_group', $courtGroup))
            ->count();
    }

    /**
     * @return list<int>
     */
    public function busyCourts(): array
    {
        return array_values(array_map('intval', GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->where('status', MatchStatus::Playing->value)
            ->whereNotNull('court_no')
            ->pluck('court_no')
            ->all()));
    }

    public function lowestFreeCourt(): ?int
    {
        $busy = $this->busyCourts();
        for ($court = 1; $court <= $this->session->courts; $court++) {
            if (! in_array($court, $busy, true)) {
                return $court;
            }
        }

        return null;
    }

    /**
     * The lowest court of $courts that has no playing match, or null.
     *
     * @param  list<int>  $courts
     */
    public function lowestFreeCourtIn(array $courts): ?int
    {
        $busy = $this->busyCourts();
        sort($courts);
        foreach ($courts as $court) {
            if ($court >= 1 && $court <= $this->session->courts && ! in_array($court, $busy, true)) {
                return $court;
            }
        }

        return null;
    }

    public function createStaged(MatchResult $result, ?int $courtGroup = null): GameMatch
    {
        $match = new GameMatch(['dupr_eligible' => true]);
        $match->play_session_id = $this->session->id;
        $match->status = MatchStatus::Staged;
        $match->court_group = $courtGroup;
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

    /**
     * Void every staged match of the session (used by a mode switch).
     */
    public function voidAllStaged(): void
    {
        GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->where('status', MatchStatus::Staged->value)
            ->update(['status' => MatchStatus::Void->value, 'updated_at' => now()]);
    }
}
