<?php

namespace App\Services\Dupr;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Support\Collection;

/**
 * Works out which done matches of a session can go into a DUPR export (PLAN Phase 4 rules).
 */
class DuprEligibility
{
    public function summarize(PlaySession $session): DuprEligibilitySummary
    {
        $matches = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Done->value)
            ->with(['matchPlayers' => fn ($q) => $q->orderBy('team')->orderBy('slot'), 'matchPlayers.player'])
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, GameMatch> $eligible */
        $eligible = new Collection;
        $skipped = [];
        /** @var array<int, array{player: Player, count: int}> $missing */
        $missing = [];

        foreach ($matches as $match) {
            if ($match->dupr_exported_at !== null) {
                $skipped[] = new DuprSkippedMatch($match, DuprSkipReason::AlreadyExported);

                continue;
            }
            if (! $match->dupr_eligible) {
                $skipped[] = new DuprSkippedMatch($match, DuprSkipReason::NotEligible);

                continue;
            }
            if ($match->team_a_score === null || $match->team_b_score === null || $match->matchPlayers->count() !== 4) {
                $skipped[] = new DuprSkippedMatch($match, DuprSkipReason::Incomplete);

                continue;
            }

            /** @var list<Player> $without */
            $without = [];
            $orphan = false;
            foreach ($match->matchPlayers as $mp) {
                /** @var MatchPlayer $mp */
                $player = $mp->player;
                if ($player === null) {
                    $orphan = true;

                    continue;
                }
                if ($player->dupr_id === null || $player->dupr_id === '') {
                    $without[] = $player;
                }
            }

            if ($orphan) {
                $skipped[] = new DuprSkippedMatch($match, DuprSkipReason::Incomplete);

                continue;
            }

            if ($without === []) {
                $eligible->push($match);

                continue;
            }

            $skipped[] = new DuprSkippedMatch($match, DuprSkipReason::MissingDuprId, $without);
            foreach ($without as $player) {
                $missing[$player->id] ??= ['player' => $player, 'count' => 0];
                $missing[$player->id]['count']++;
            }
        }

        $missingPlayers = array_map(
            fn (array $m): DuprMissingPlayer => new DuprMissingPlayer($m['player'], $m['count']),
            array_values($missing),
        );
        usort($missingPlayers, fn (DuprMissingPlayer $a, DuprMissingPlayer $b): int => [$b->blockedMatches, $a->player->name] <=> [$a->blockedMatches, $b->player->name]);

        return new DuprEligibilitySummary($eligible, $skipped, $missingPlayers);
    }
}
