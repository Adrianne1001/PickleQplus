<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\Candidate;
use App\Domain\Rotation\ReplacementResult;
use App\Domain\Rotation\SkillGroups;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Models\GameMatch;
use App\Models\PlaySession;

/**
 * Skill courts: the courts are split into groups, each with a star range. Every
 * group has its own balanced queue (the waiting players whose current stars fall
 * in its range) and its own Up Next slots, and a staged match only ever starts on
 * a court of its group. Strict: an idle court never takes another group's match.
 *
 * A player's stars are read when a match is staged, so a stars change moves them
 * to the new group for the next staging and leaves staged matches alone. A swap may
 * put a player from another group into a match (staff override).
 */
class SkillCourtsStrategy extends BalancedStrategy
{
    /**
     * The session's groups: the stored ones when valid for its court count, else the
     * defaults. Null when the session is not in skill courts or has fewer than 2 courts.
     */
    public static function groupsFor(PlaySession $session): ?SkillGroups
    {
        if ($session->rotation_mode !== RotationMode::SkillCourts) {
            return null;
        }

        return SkillGroups::tryFromArray($session->mode_settings['skill_groups'], $session->courts)
            ?? ($session->courts >= SkillGroups::MIN_COURTS ? SkillGroups::defaultFor($session->courts) : null);
    }

    public function fillUpNext(PlaySession $session, RotationQueries $queries): void
    {
        $groups = self::groupsFor($session);

        // Every staged match of a skill session belongs to a group; anything else is stale.
        // Per group the oldest matches stay and the newest surplus is voided, like balanced mode.
        $staged = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->orderBy('id')
            ->get();
        $count = $groups?->count() ?? 0;
        $kept = [];
        foreach ($staged as $match) {
            $group = $match->court_group;
            if ($group === null || $group < 1 || $group > $count || ($kept[$group] ?? 0) >= $session->up_next_count) {
                $match->status = MatchStatus::Void;
                $match->save();

                continue;
            }
            $kept[$group] = ($kept[$group] ?? 0) + 1;
        }

        if ($groups === null) {
            return;
        }

        // Candidates and history are read once. Players of a match staged here leave the pool, and
        // players of different groups never meet, so the history stays exact for every later pick.
        $pool = $this->waiting($session, $queries);
        $history = $queries->history();
        for ($group = 1; $group <= $count; $group++) {
            $candidates = $this->inGroup($groups, $group, $pool);
            while (($kept[$group] ?? 0) < $session->up_next_count) {
                $result = $this->engine->pickMatch($candidates, $history);
                if ($result === null) {
                    break;
                }
                $queries->createStaged($result, $group);
                $kept[$group] = ($kept[$group] ?? 0) + 1;
                $taken = [...$result->teamA, ...$result->teamB];
                $candidates = array_values(array_filter($candidates, static fn (Candidate $c): bool => ! in_array($c->id, $taken, true)));
            }
        }
    }

    public function reroll(PlaySession $session, RotationQueries $queries, array $previousPlayerIds, GameMatch $voided): void
    {
        $groups = self::groupsFor($session);
        $group = $voided->court_group;
        if ($groups === null || $group === null || $group < 1 || $group > $groups->count()) {
            return;
        }

        // Leave-one-out inside the group, exactly like balanced mode.
        $candidates = $this->inGroup($groups, $group, $this->waiting($session, $queries));
        $history = $queries->history();
        $best = null;
        foreach ($previousPlayerIds as $leaveOut) {
            $result = $this->engine->pickMatch(
                array_values(array_filter($candidates, fn (Candidate $c): bool => $c->id !== $leaveOut)),
                $history,
            );
            if ($result !== null && ($best === null || $result->cost < $best->cost)) {
                $best = $result;
            }
        }
        if ($best !== null) {
            $queries->createStaged($best, $group);
        }
    }

    public function pickReplacement(PlaySession $session, RotationQueries $queries, array $teamA, array $teamB, int $exceptMatchId): ?ReplacementResult
    {
        $groups = self::groupsFor($session);
        $match = GameMatch::query()->whereKey($exceptMatchId)->first(['id', 'court_group', 'court_no']);
        $group = $groups === null || $match === null ? null : $this->groupOf($groups, $match);
        if ($groups === null || $group === null) {
            return null; // strict: with no group to pick from, nobody is picked
        }

        return $this->engine->pickReplacement(
            $teamA,
            $teamB,
            $this->inGroup($groups, $group, $this->waiting($session, $queries)),
            $queries->history($exceptMatchId),
        );
    }

    public function allowedCourts(PlaySession $session, GameMatch $match): array
    {
        $groups = self::groupsFor($session);
        $group = $match->court_group;
        if ($groups === null || $group === null || $group < 1 || $group > $groups->count()) {
            return []; // strict: a match with no valid group starts nowhere
        }

        return $groups->courtRange($group);
    }

    /**
     * The group a match belongs to now. A match on a court is in that court's group, which
     * stays right after a group edit and for matches that started before the switch to skill
     * courts. A staged match has no court, so its stored group index is used when it is valid.
     */
    private function groupOf(SkillGroups $groups, GameMatch $match): ?int
    {
        $byCourt = $match->court_no === null ? null : $groups->groupForCourt($match->court_no);
        if ($byCourt !== null) {
            return $byCourt;
        }

        $stored = $match->court_group;

        return $stored !== null && $stored >= 1 && $stored <= $groups->count() ? $stored : null;
    }

    /**
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    private function inGroup(SkillGroups $groups, int $group, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (Candidate $c): bool => $groups->tryGroupForStars($c->stars) === $group,
        ));
    }
}
