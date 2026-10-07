<?php

use App\Domain\Rotation\SkillGroups;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use App\Services\PublicSessionView;
use App\Services\SessionBoard;
use Illuminate\Validation\ValidationException;

/**
 * A live skill courts session with default groups (courts split in two: the top half for
 * 4 to 6 stars, the rest for 1 to 3) and no players yet.
 *
 * @param  array<string, mixed>  $attrs
 */
function skillBoard(array $attrs = []): PlaySession
{
    $courts = $attrs['courts'] ?? 4;

    return PlaySession::factory()->for(Club::factory()->create())->live()->create([
        'rotation_mode' => RotationMode::SkillCourts,
        'courts' => $courts,
        'up_next_count' => 1,
        'mode_settings' => ['winners_stay_max_wins' => 2, 'skill_groups' => SkillGroups::defaultFor($courts)->toArray()],
        ...$attrs,
    ]);
}

/**
 * Check players with these star ratings into the session, one second apart.
 *
 * @param  list<int>  $stars
 * @return list<Player>
 */
function skillJoin(PlaySession $session, array $stars): array
{
    $players = [];
    foreach ($stars as $s) {
        $player = Player::factory()->for($session->club)->manual($s)->create();
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return $players;
}

/** @return list<GameMatch> */
function skillMatches(PlaySession $session, MatchStatus $status): array
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', $status->value)->orderBy('id')->get()->all();
}

/** @return list<int> the stars of a match's players, sorted */
function matchStars(GameMatch $match): array
{
    return $match->matchPlayers()->with('player')->get()->map(fn ($mp) => $mp->player->stars)->sort()->values()->all();
}

function skillSvc(): PlaySessionService
{
    return app(PlaySessionService::class);
}

// --- staging per group ---

test('each group stages its own Up Next from the players in its star range', function () {
    $session = skillBoard();
    skillJoin($session, [5, 2, 6, 1, 4, 3, 5, 2]);

    $staged = skillMatches($session, MatchStatus::Staged);

    expect($staged)->toHaveCount(2)
        ->and($staged[0]->court_group)->toBe(1)->and(matchStars($staged[0]))->toBe([4, 5, 5, 6])
        ->and($staged[1]->court_group)->toBe(2)->and(matchStars($staged[1]))->toBe([1, 2, 2, 3]);
});

test('every group has up_next_count slots of its own', function () {
    $session = skillBoard(['up_next_count' => 2]);
    skillJoin($session, [...array_fill(0, 8, 5), ...array_fill(0, 8, 2)]);

    $staged = collect(skillMatches($session, MatchStatus::Staged));

    expect($staged->where('court_group', 1))->toHaveCount(2)
        ->and($staged->where('court_group', 2))->toHaveCount(2)
        ->and($staged)->toHaveCount(4);
});

test('a group without enough players stages nothing and does not borrow from another group', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 2, 2, 2, 2, 2]);

    $staged = skillMatches($session, MatchStatus::Staged);

    expect($staged)->toHaveCount(1)->and($staged[0]->court_group)->toBe(2);
});

test('lowering up_next_count voids the newest surplus of each group and keeps the oldest', function () {
    $session = skillBoard(['up_next_count' => 2]);
    skillJoin($session, [...array_fill(0, 8, 5), ...array_fill(0, 8, 2)]);
    $before = collect(skillMatches($session, MatchStatus::Staged));
    $oldest = [1 => $before->where('court_group', 1)->first(), 2 => $before->where('court_group', 2)->first()];
    $newest = [1 => $before->where('court_group', 1)->last(), 2 => $before->where('court_group', 2)->last()];
    expect($oldest[1]->id)->not->toBe($newest[1]->id);

    skillSvc()->update($session->fresh(), ['up_next_count' => 1]);

    $staged = collect(skillMatches($session, MatchStatus::Staged));
    expect($staged)->toHaveCount(2)
        ->and($staged->pluck('id')->sort()->values()->all())->toBe(collect([$oldest[1]->id, $oldest[2]->id])->sort()->values()->all())
        ->and($newest[1]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($newest[2]->fresh()->status)->toBe(MatchStatus::Void);
});

test('a staged match with no group or a stale group is voided on refill and restaged', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5, 5]);
    $match = skillMatches($session, MatchStatus::Staged)[0];

    foreach ([null, 9] as $bad) {
        $match->court_group = $bad;
        $match->save();

        skillSvc()->update($session->fresh(), ['name' => 'Renamed '.($bad ?? 'null')]);

        expect($match->fresh()->status)->toBe(MatchStatus::Void);
        $match = skillMatches($session, MatchStatus::Staged)[0];
        expect($match->court_group)->toBe(1);
    }
});

test('a staged match with no valid group cannot be started anywhere', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5]);
    $match = skillMatches($session, MatchStatus::Staged)[0];
    $match->court_group = null;
    $match->save();

    expect(fn () => app(MatchService::class)->startMatch($session, $match))->toThrow(ValidationException::class, "No court is free in this match's skill group.")
        ->and(fn () => app(MatchService::class)->startMatch($session, $match, 1))->toThrow(ValidationException::class, "That court is not in this match's skill group.");
    expect($match->fresh()->status)->toBe(MatchStatus::Staged);
});

// --- starting on a court of the group ---

test('a staged match starts on the lowest free court of its group', function () {
    $session = skillBoard(['auto_fill' => true]);
    skillJoin($session, [...array_fill(0, 8, 5), ...array_fill(0, 8, 2)]);

    $playing = collect(skillMatches($session, MatchStatus::Playing));

    expect($playing->pluck('court_no')->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and($playing->where('court_group', 1)->pluck('court_no')->sort()->values()->all())->toBe([1, 2])
        ->and($playing->where('court_group', 2)->pluck('court_no')->sort()->values()->all())->toBe([3, 4]);
});

test('a low group match never takes a top court, even when it is the only match and the top courts are idle', function () {
    $session = skillBoard(['auto_fill' => true]);
    skillJoin($session, [2, 2, 2, 2]);

    $playing = skillMatches($session, MatchStatus::Playing);

    expect($playing)->toHaveCount(1)->and($playing[0]->court_no)->toBe(3);
});

test('starting without a court picks the lowest free court of the match\'s group', function () {
    $session = skillBoard();
    skillJoin($session, [2, 2, 2, 2]);
    $match = skillMatches($session, MatchStatus::Staged)[0];

    app(MatchService::class)->startMatch($session, $match);

    expect($match->fresh()->court_no)->toBe(3)->and($match->fresh()->court_group)->toBe(2);
});

test('a manually chosen court outside the match\'s group is rejected', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5]);
    $match = skillMatches($session, MatchStatus::Staged)[0];

    foreach ([3, 4] as $court) {
        try {
            app(MatchService::class)->startMatch($session, $match, $court);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['court' => ['That court is not in this match\'s skill group.']]);
        }
    }
    expect($match->fresh()->status)->toBe(MatchStatus::Staged);

    app(MatchService::class)->startMatch($session, $match, 2);
    expect($match->fresh()->court_no)->toBe(2);
});

test('a court that does not exist or is in use keeps its own error', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5, 5, 5, 5, 5]);
    $first = skillMatches($session, MatchStatus::Staged)[0];
    app(MatchService::class)->startMatch($session, $first, 1);

    $next = skillMatches($session, MatchStatus::Staged)[0];

    expect(fn () => app(MatchService::class)->startMatch($session, $next, 9))->toThrow(ValidationException::class, 'That court does not exist in this session.')
        ->and(fn () => app(MatchService::class)->startMatch($session, $next, 1))->toThrow(ValidationException::class, 'That court is in use.');
});

// --- strict ---

test('an idle court never takes another group\'s match, and a full group makes the match wait', function () {
    $session = skillBoard(['auto_fill' => true]);
    skillJoin($session, array_fill(0, 12, 5)); // 3 top-group matches, the top group has 2 courts

    $playing = skillMatches($session, MatchStatus::Playing);
    $staged = skillMatches($session, MatchStatus::Staged);

    expect($playing)->toHaveCount(2)
        ->and(collect($playing)->pluck('court_no')->sort()->values()->all())->toBe([1, 2])
        ->and($staged)->toHaveCount(1)->and($staged[0]->court_group)->toBe(1);

    // Courts 3 and 4 are idle but stay idle, and a manual start there is refused.
    expect(app(SessionBoard::class)->freeCourts($session))->toBe([3, 4])
        ->and(fn () => app(MatchService::class)->startMatch($session, $staged[0], 3))->toThrow(ValidationException::class);

    // With no free court in the group, starting without a court says so.
    expect(fn () => app(MatchService::class)->startMatch($session, $staged[0]))
        ->toThrow(ValidationException::class, 'No court is free in this match\'s skill group.');
});

test('auto_fill skips an older match whose group is full and starts a younger match of a free group', function () {
    $session = skillBoard(['auto_fill' => true]);
    skillJoin($session, array_fill(0, 12, 5)); // courts 1-2 busy, one top match waiting (older)
    skillJoin($session, [2, 2, 2, 2]);

    $playing = collect(skillMatches($session, MatchStatus::Playing));
    $staged = skillMatches($session, MatchStatus::Staged);

    expect($playing)->toHaveCount(3)
        ->and($playing->where('court_group', 2)->pluck('court_no')->all())->toBe([3])
        ->and($staged)->toHaveCount(1)->and($staged[0]->court_group)->toBe(1);
});

test('when a court frees up the waiting match of that group takes it', function () {
    $session = skillBoard(['auto_fill' => true]);
    skillJoin($session, array_fill(0, 12, 5));
    $onCourtTwo = GameMatch::query()->where('play_session_id', $session->id)->where('court_no', 2)->where('status', MatchStatus::Playing->value)->firstOrFail();
    $waiting = skillMatches($session, MatchStatus::Staged)[0];

    app(MatchService::class)->finish($session, $onCourtTwo, 11, 3);

    expect($waiting->fresh()->status)->toBe(MatchStatus::Playing)->and($waiting->fresh()->court_no)->toBe(2);
});

// --- re-roll, remove, swap ---

test('re-roll stages a different match inside the same group', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5, 6, 6, 2, 2, 2, 2]);
    $high = skillMatches($session, MatchStatus::Staged)[0];
    $low = skillMatches($session, MatchStatus::Staged)[1];
    expect($high->court_group)->toBe(1)->and($low->court_group)->toBe(2);
    $before = matchIds($high);

    app(MatchService::class)->reroll($session, $high);

    $staged = skillMatches($session, MatchStatus::Staged);
    $new = collect($staged)->firstWhere('court_group', 1);
    expect($high->fresh()->status)->toBe(MatchStatus::Void)
        ->and($staged)->toHaveCount(2)
        ->and(matchIds($new))->not->toBe($before)
        ->and(min(matchStars($new)))->toBeGreaterThanOrEqual(4)
        ->and($low->fresh()->status)->toBe(MatchStatus::Staged);
});

test('re-roll with nobody else in the group re-stages the same players', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2, 2, 2]);
    $high = skillMatches($session, MatchStatus::Staged)[0];
    $before = matchIds($high);

    app(MatchService::class)->reroll($session, $high);

    $new = collect(skillMatches($session, MatchStatus::Staged))->firstWhere('court_group', 1);
    expect(matchIds($new))->toBe($before);
});

test('remove picks the replacement from the match\'s group only', function () {
    $session = skillBoard();
    $high = skillJoin($session, [5, 5, 5, 5, 5]);
    skillJoin($session, [2, 2, 2, 2, 2]);
    $match = collect(skillMatches($session, MatchStatus::Staged))->firstWhere('court_group', 1);
    $out = Player::findOrFail($match->matchPlayers()->first()->player_id);
    $waitingHigh = collect($high)->first(fn (Player $p) => ! in_array($p->id, matchIds($match), true));

    app(MatchService::class)->remove($session, $match, $out);

    expect(matchIds($match->fresh()))->toContain($waitingHigh->id)
        ->and(min(matchStars($match->fresh())))->toBeGreaterThanOrEqual(4);
});

test('remove is blocked when nobody in the group is available, even if other groups have waiting players', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5]);
    skillJoin($session, [2, 2, 2, 2, 2]);
    $match = collect(skillMatches($session, MatchStatus::Staged))->firstWhere('court_group', 1);
    $out = Player::findOrFail($match->matchPlayers()->first()->player_id);

    expect(fn () => app(MatchService::class)->remove($session, $match, $out))
        ->toThrow(ValidationException::class, 'No waiting player is available to take that slot.');
    expect(matchIds($match->fresh()))->toContain($out->id);
});

test('a swap may cross groups and the match keeps its group and courts', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5, 5]);
    [$low] = skillJoin($session, [2]);
    $match = skillMatches($session, MatchStatus::Staged)[0];
    $out = Player::findOrFail($match->matchPlayers()->first()->player_id);

    app(MatchService::class)->swap($session, $match, $out, $low);

    expect(matchIds($match->fresh()))->toContain($low->id)
        ->and($match->fresh()->court_group)->toBe(1)
        ->and($match->fresh()->status)->toBe(MatchStatus::Staged);

    app(MatchService::class)->startMatch($session, $match->fresh());
    expect($match->fresh()->court_no)->toBe(1);
});

test('remove on a playing match uses the group of its court even after a groups edit left a stale index', function () {
    $session = skillBoard(['courts' => 6, 'auto_fill' => true, 'mode_settings' => ['winners_stay_max_wins' => 2, 'skill_groups' => [
        ['from_court' => 1, 'to_court' => 2, 'min_stars' => 5, 'max_stars' => 6],
        ['from_court' => 3, 'to_court' => 4, 'min_stars' => 3, 'max_stars' => 4],
        ['from_court' => 5, 'to_court' => 6, 'min_stars' => 1, 'max_stars' => 2],
    ]]]);
    skillJoin($session, [2, 2, 2, 2]);
    $playing = skillMatches($session, MatchStatus::Playing)[0];
    expect($playing->court_group)->toBe(3)->and($playing->court_no)->toBe(5);

    // Three groups become two: the match keeps index 3, which no longer exists, but court 5 is in group 2 (1 to 3 stars).
    skillSvc()->update($session->fresh(), ['mode_settings' => ['skill_groups' => SkillGroups::defaultFor(6)->toArray()]]);
    expect($playing->fresh()->court_group)->toBe(3)->and($playing->fresh()->status)->toBe(MatchStatus::Playing);

    $out = Player::findOrFail($playing->matchPlayers()->first()->player_id);
    [$high] = skillJoin($session->fresh(), [5]);

    // Only a 5 star waits and it is not in the court's group: nobody is picked.
    expect(fn () => app(MatchService::class)->remove($session->fresh(), $playing, $out))
        ->toThrow(ValidationException::class, 'No waiting player is available to take that slot.');

    [$low] = skillJoin($session->fresh(), [3]);
    app(MatchService::class)->remove($session->fresh(), $playing, $out);
    expect(matchIds($playing->fresh()))->toContain($low->id)->not->toContain($high->id);
});

test('remove on a match that started before the switch to skill courts resolves its group by court', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->live()->create(['courts' => 4, 'up_next_count' => 1, 'auto_fill' => true]);
    skillJoin($session, [2, 2, 2, 2]);
    $playing = skillMatches($session, MatchStatus::Playing)[0];
    expect($playing->court_group)->toBeNull()->and($playing->court_no)->toBe(1);

    skillSvc()->update($session->fresh(), ['rotation_mode' => 'skill_courts']);
    [$low, $high] = skillJoin($session->fresh(), [2, 5]);
    $out = Player::findOrFail($playing->matchPlayers()->first()->player_id);

    // Court 1 is in the top group (4 to 6 stars), so the 5 star takes the slot, not the 2 star.
    app(MatchService::class)->remove($session->fresh(), $playing, $out);

    expect(matchIds($playing->fresh()))->toContain($high->id)->not->toContain($low->id);
});

test('a live switch away from skill courts and back voids Up Next, keeps playing matches and applies the default groups', function () {
    $custom = [
        ['from_court' => 1, 'to_court' => 1, 'min_stars' => 5, 'max_stars' => 6],
        ['from_court' => 2, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 4],
    ];
    $session = skillBoard(['courts' => 4, 'mode_settings' => ['winners_stay_max_wins' => 2, 'skill_groups' => $custom]]);
    skillJoin($session, array_fill(0, 12, 6));
    app(MatchService::class)->startMatch($session, skillMatches($session, MatchStatus::Staged)[0]);
    $playing = skillMatches($session, MatchStatus::Playing);
    $staged = skillMatches($session, MatchStatus::Staged);
    expect($playing)->not->toBeEmpty()->and($staged)->toHaveCount(1);

    skillSvc()->update($session->fresh(), ['rotation_mode' => 'balanced']);
    $balancedStaged = skillMatches($session, MatchStatus::Staged);
    expect($staged[0]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($balancedStaged)->toHaveCount(1)->and($balancedStaged[0]->court_group)->toBeNull()
        ->and($session->fresh()->mode_settings['skill_groups'])->toBe($custom);

    skillSvc()->update($session->fresh(), ['rotation_mode' => 'skill_courts']);

    expect($balancedStaged[0]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($session->fresh()->mode_settings['skill_groups'])->toBe(SkillGroups::defaultFor(4)->toArray());
    foreach ($playing as $m) {
        expect($m->fresh()->status)->toBe(MatchStatus::Playing);
    }
    expect(skillMatches($session, MatchStatus::Staged)[0]->court_group)->toBe(1);
});

test('invalid stored groups do not make every edit count as a groups change', function () {
    $session = skillBoard(['courts' => 4]);
    skillJoin($session, [5, 5, 5, 5]);
    $session->mode_settings = ['winners_stay_max_wins' => 2, 'skill_groups' => [['from_court' => 1]]];
    $session->save();

    expect(skillSvc()->changeVoidsUpNext($session->fresh(), ['mode_settings' => ['skill_groups' => SkillGroups::defaultFor(4)->toArray()]]))->toBeFalse();
});

// --- stars change ---

test('a stars change moves the player to the new group for the next staging only', function () {
    $session = skillBoard();
    skillJoin($session, [5, 5, 5]);
    $lows = skillJoin($session, [2, 2, 2, 2, 2]);
    $lowMatch = skillMatches($session, MatchStatus::Staged)[0];
    expect($lowMatch->court_group)->toBe(2);

    // The one low player not in the staged match becomes a 5 star.
    $mover = collect($lows)->first(fn (Player $p) => ! in_array($p->id, matchIds($lowMatch), true));
    $mover->stars = 5;
    $mover->save();

    // Nothing restages until the next state change, and the staged match is never touched.
    expect(skillMatches($session, MatchStatus::Staged))->toHaveCount(1);

    skillSvc()->update($session->fresh(), ['name' => 'Renamed']);

    $staged = collect(skillMatches($session, MatchStatus::Staged));
    $top = $staged->firstWhere('court_group', 1);
    expect($staged)->toHaveCount(2)
        ->and($staged->firstWhere('court_group', 2)->id)->toBe($lowMatch->id)
        ->and($lowMatch->fresh()->status)->toBe(MatchStatus::Staged)
        ->and(matchIds($top))->toContain($mover->id);
});

test('a stars change does not touch an existing staged match, even when the stars no longer fit its group', function () {
    $session = skillBoard();
    $players = skillJoin($session, [5, 5, 5, 5]);
    $match = skillMatches($session, MatchStatus::Staged)[0];

    $players[0]->stars = 1;
    $players[0]->save();
    skillSvc()->update($session->fresh(), ['name' => 'Renamed']);

    expect($match->fresh()->status)->toBe(MatchStatus::Staged)->and($match->fresh()->court_group)->toBe(1)
        ->and(matchIds($match->fresh()))->toContain($players[0]->id);
});

// --- settings ---

test('switching to skill courts without groups uses the defaults and voids Up Next', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create(['courts' => 4, 'up_next_count' => 1]);
    skillJoin($session, [5, 5, 5, 5]);
    $staged = skillMatches($session, MatchStatus::Staged)[0];

    expect(skillSvc()->changeVoidsUpNext($session, ['rotation_mode' => 'skill_courts']))->toBeTrue();
    skillSvc()->update($session, ['rotation_mode' => 'skill_courts']);

    $fresh = $session->fresh();
    expect($fresh->rotation_mode)->toBe(RotationMode::SkillCourts)
        ->and($fresh->mode_settings['skill_groups'])->toBe(SkillGroups::defaultFor(4)->toArray())
        ->and($staged->fresh()->status)->toBe(MatchStatus::Void);

    $restaged = skillMatches($session, MatchStatus::Staged);
    expect($restaged)->toHaveCount(1)->and($restaged[0]->court_group)->toBe(1);
});

test('switching to skill courts takes groups from the input and validates them', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->create(['courts' => 4]);
    $groups = [
        ['from_court' => 1, 'to_court' => 1, 'min_stars' => 5, 'max_stars' => 6],
        ['from_court' => 2, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 4],
    ];

    skillSvc()->update($session, ['rotation_mode' => 'skill_courts', 'mode_settings' => ['skill_groups' => $groups]]);
    expect($session->fresh()->mode_settings['skill_groups'])->toBe($groups);

    foreach ([
        'a single group' => [['from_court' => 1, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 6]],
        'a court gap' => [['from_court' => 1, 'to_court' => 1, 'min_stars' => 4, 'max_stars' => 6], ['from_court' => 3, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 3]],
        'star overlap' => [['from_court' => 1, 'to_court' => 2, 'min_stars' => 3, 'max_stars' => 6], ['from_court' => 3, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 3]],
    ] as $bad) {
        try {
            skillSvc()->update($session, ['mode_settings' => ['skill_groups' => $bad]]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('mode_settings.skill_groups');
        }
    }
    expect($session->fresh()->mode_settings['skill_groups'])->toBe($groups);
});

test('skill courts needs at least 2 courts', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->create(['courts' => 1]);

    expect(fn () => skillSvc()->update($session, ['rotation_mode' => 'skill_courts']))
        ->toThrow(ValidationException::class, 'Skill courts needs at least 2 courts.');

    $skill = skillBoard();
    expect(fn () => skillSvc()->update($skill, ['courts' => 1]))->toThrow(ValidationException::class, 'Skill courts needs at least 2 courts.')
        ->and($skill->fresh()->courts)->toBe(4);
});

test('other modes ignore skill groups but keep them stored', function () {
    $session = skillBoard(['courts' => 4]);
    $stored = $session->mode_settings['skill_groups'];

    skillSvc()->update($session, ['rotation_mode' => 'balanced']);
    expect($session->fresh()->mode_settings['skill_groups'])->toBe($stored);

    skillSvc()->update($session->fresh(), ['courts' => 6]);
    expect($session->fresh()->mode_settings['skill_groups'])->toBe($stored)
        ->and($session->fresh()->courts)->toBe(6);

    // Balanced stages from the whole queue and never sets a group.
    [$balanced] = board(4, ['courts' => 2, 'up_next_count' => 1]);
    expect(skillMatches($balanced, MatchStatus::Staged)[0]->court_group)->toBeNull();
});

test('changing courts in skill courts resizes the last group', function () {
    $session = skillBoard(['courts' => 4]);

    skillSvc()->update($session, ['courts' => 6]);
    expect($session->fresh()->mode_settings['skill_groups'])->toBe([
        ['from_court' => 1, 'to_court' => 2, 'min_stars' => 4, 'max_stars' => 6],
        ['from_court' => 3, 'to_court' => 6, 'min_stars' => 1, 'max_stars' => 3],
    ]);

    skillSvc()->update($session->fresh(), ['courts' => 3]);
    expect($session->fresh()->mode_settings['skill_groups'])->toBe([
        ['from_court' => 1, 'to_court' => 2, 'min_stars' => 4, 'max_stars' => 6],
        ['from_court' => 3, 'to_court' => 3, 'min_stars' => 1, 'max_stars' => 3],
    ]);
});

test('a courts change that would leave a group with no courts is rejected', function () {
    $session = skillBoard(['courts' => 4]);

    try {
        skillSvc()->update($session, ['courts' => 2]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['courts' => ['That court count would leave a skill group with no courts. Edit the groups first.']]);
    }
    expect($session->fresh()->courts)->toBe(4)
        ->and(skillSvc()->changeVoidsUpNext($session, ['courts' => 2]))->toBeFalse();

    // Passing groups that fit the new court count is the way out.
    skillSvc()->update($session, ['courts' => 2, 'mode_settings' => ['skill_groups' => SkillGroups::defaultFor(2)->toArray()]]);
    expect($session->fresh()->courts)->toBe(2);
});

test('a courts change while live voids Up Next and the staged matches follow the new groups', function () {
    $session = skillBoard(['courts' => 4]);
    skillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2]);
    $before = skillMatches($session, MatchStatus::Staged);
    expect($before)->toHaveCount(2)
        ->and(skillSvc()->changeVoidsUpNext($session, ['courts' => 5]))->toBeTrue()
        ->and(skillSvc()->changeVoidsUpNext($session, ['courts' => 4]))->toBeFalse()
        ->and(skillSvc()->changeVoidsUpNext($session, ['name' => 'x']))->toBeFalse();

    skillSvc()->update($session, ['courts' => 5]);

    $after = skillMatches($session, MatchStatus::Staged);
    expect($before[0]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($before[1]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($after)->toHaveCount(2);
    expect(app(MatchService::class)->startMatch($session->fresh(), collect($after)->firstWhere('court_group', 2)))->not->toBeNull();
    expect(GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->value('court_no'))->toBe(3);
});

test('a courts change keeps matches in progress, and a court with a match cannot be removed', function () {
    $session = skillBoard(['courts' => 4, 'auto_fill' => true]);
    skillJoin($session, [2, 2, 2, 2, 2, 2, 2, 2]); // courts 3 and 4 busy

    expect(fn () => skillSvc()->update($session, ['courts' => 3]))->toThrow(ValidationException::class, 'A court with a match in progress cannot be removed.');

    skillSvc()->update($session, ['courts' => 5]);
    expect(skillMatches($session, MatchStatus::Playing))->toHaveCount(2);
});

test('editing the groups voids staged matches, keeps playing ones and restages under the new groups', function () {
    $session = skillBoard(['courts' => 4, 'auto_fill' => true]);
    skillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2]); // both started
    skillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2, 5, 5, 5, 5]);
    $playing = skillMatches($session, MatchStatus::Playing);
    $staged = skillMatches($session, MatchStatus::Staged);
    expect($staged)->not->toBeEmpty();

    $new = [
        ['from_court' => 1, 'to_court' => 1, 'min_stars' => 5, 'max_stars' => 6],
        ['from_court' => 2, 'to_court' => 4, 'min_stars' => 1, 'max_stars' => 4],
    ];
    expect(skillSvc()->changeVoidsUpNext($session, ['mode_settings' => ['skill_groups' => $new]]))->toBeTrue();
    skillSvc()->update($session, ['mode_settings' => ['skill_groups' => $new]]);

    foreach ($staged as $m) {
        expect($m->fresh()->status)->toBe(MatchStatus::Void);
    }
    foreach ($playing as $m) {
        expect($m->fresh()->status)->toBe(MatchStatus::Playing);
    }
    expect($session->fresh()->mode_settings['skill_groups'])->toBe($new);

    // New staging follows the new star ranges: group 1 only takes 5 and 6 stars.
    foreach (skillMatches($session, MatchStatus::Staged) as $m) {
        $stars = matchStars($m);
        expect($m->court_group === 1 ? min($stars) >= 5 : max($stars) <= 4)->toBeTrue();
    }
});

test('saving the same groups, or a max wins change, keeps the staged matches', function () {
    $session = skillBoard(['courts' => 4]);
    skillJoin($session, [5, 5, 5, 5]);
    $staged = skillMatches($session, MatchStatus::Staged)[0];
    $same = array_map(fn (array $g) => array_reverse($g, true), SkillGroups::defaultFor(4)->toArray()); // different key order

    expect(skillSvc()->changeVoidsUpNext($session, ['mode_settings' => ['skill_groups' => $same]]))->toBeFalse()
        ->and(skillSvc()->changeVoidsUpNext($session, ['mode_settings' => ['winners_stay_max_wins' => 4]]))->toBeFalse()
        ->and(skillSvc()->changeVoidsUpNext($session, ['rotation_mode' => 'skill_courts']))->toBeFalse();

    skillSvc()->update($session, ['mode_settings' => ['skill_groups' => $same, 'winners_stay_max_wins' => 4]]);

    expect($staged->fresh()->status)->toBe(MatchStatus::Staged)
        ->and($session->fresh()->mode_settings['winners_stay_max_wins'])->toBe(4);
});

// --- read model ---

test('the board gives groups, per group queue positions and per group estimates', function () {
    $session = skillBoard(['courts' => 4, 'auto_fill' => true]);
    skillJoin($session, array_fill(0, 8, 5)); // top courts 1 and 2 busy
    skillJoin($session, array_fill(0, 5, 5)); // 1 staged top match, 1 waiting
    skillJoin($session, [2]);                 // waits alone in the low group

    $board = app(SessionBoard::class);
    $waiting = $board->waiting($session->fresh());
    $groups = $board->groups($session->fresh(), $waiting);

    expect($groups)->toHaveCount(2)
        ->and($groups[0])->toBe(['index' => 1, 'label' => 'Courts 1–2', 'min_stars' => 4, 'max_stars' => 6, 'courts' => [1, 2], 'waiting' => 1, 'staged' => 1])
        ->and($groups[1])->toBe(['index' => 2, 'label' => 'Courts 3–4', 'min_stars' => 1, 'max_stars' => 3, 'courts' => [3, 4], 'waiting' => 1, 'staged' => 0]);

    $top = collect($waiting)->firstWhere('group', 1);
    $low = collect($waiting)->firstWhere('group', 2);

    // Top group: both its courts are busy (15 min average), 1 staged match first, so the waiting
    // player's match is second in line: it starts when the second court frees at 15 minutes.
    // A single shared queue over 4 courts would have promised 0 minutes.
    expect($top['group_position'])->toBe(1)->and($top['estimate_minutes'])->toBe(15)
        ->and($low['group_position'])->toBe(1)->and($low['estimate_minutes'])->toBe(0);

    $staged = $board->staged($session->fresh());
    expect($staged)->toHaveCount(1)->and($staged[0]['group'])->toBe(1);
});

test('the board has no groups and null group fields outside skill courts', function () {
    [$session] = board(5, ['courts' => 2]);
    $board = app(SessionBoard::class);

    expect($board->groups($session))->toBe([])
        ->and(collect($board->waiting($session))->pluck('group')->unique()->all())->toBe([null])
        ->and($board->staged($session)[0]['group'])->toBeNull();
});

test('the public view labels groups without stars and never exposes a star rating', function () {
    $session = skillBoard(['courts' => 4, 'auto_fill' => true]);
    skillJoin($session, array_fill(0, 8, 5));
    skillJoin($session, array_fill(0, 5, 5));
    skillJoin($session, [2]);

    $snap = app(PublicSessionView::class)->snapshot($session->fresh());

    expect($snap['groups'])->toBe([
        ['index' => 1, 'label' => 'Courts 1–2', 'courts' => [1, 2]],
        ['index' => 2, 'label' => 'Courts 3–4', 'courts' => [3, 4]],
    ])
        ->and($snap['up_next'])->toHaveCount(1)->and($snap['up_next'][0]['group'])->toBe(1)
        ->and(collect($snap['waiting'])->pluck('group_position')->all())->each->toBe(1)
        ->and(collect($snap['waiting'])->pluck('group')->sort()->values()->all())->toBe([1, 2]);

    $json = (string) json_encode($snap);
    expect($json)->not->toContain('stars')->not->toContain('min_')->not->toContain('max_');
});

test('the public view has an empty group list in other modes and before the session is live', function () {
    [$session] = board(4, ['courts' => 2]);
    $draft = PlaySession::factory()->for($session->club)->create();

    expect(app(PublicSessionView::class)->snapshot($session)['groups'])->toBe([])
        ->and(app(PublicSessionView::class)->snapshot($draft)['groups'])->toBe([]);
});
