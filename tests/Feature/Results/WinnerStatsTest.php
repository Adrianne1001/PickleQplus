<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\StatsPeriod;
use App\Enums\Team;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\Dupr\DuprEligibility;
use App\Services\Dupr\DuprSkipReason;
use App\Services\PublicSessionView;
use App\Services\SessionBoard;
use App\Services\SessionResultsService;
use App\Services\StatsService;
use Database\Factories\GameMatchFactory;

/**
 * An ended session with four players (Ann, Bob, Cy, Di) and three done matches, all Ann & Bob vs Cy & Di:
 * a scored 11-5 win for A, a winner-only win for B, and a winner-only win for A.
 *
 * @return array{0: Club, 1: PlaySession, 2: array<string, Player>}
 */
function winnerWorld(): array
{
    $club = Club::factory()->create(['leaderboard_min_games' => 1]);
    $session = PlaySession::factory()->for($club)->ended()->create(['require_score' => false]);
    $p = [];
    foreach (['Ann', 'Bob', 'Cy', 'Di'] as $name) {
        $p[$name] = Player::factory()->for($club)->create(['name' => $name]);
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $p[$name]->id, 'status' => SessionPlayerStatus::Waiting]);
    }
    winnerMatch($session, $p, GameMatch::factory()->done(Team::A, 11, 5), 30);
    winnerMatch($session, $p, GameMatch::factory()->winnerOnly(Team::B), 20);
    winnerMatch($session, $p, GameMatch::factory()->winnerOnly(Team::A), 10);

    return [$club, $session, $p];
}

/**
 * @param  array<string, Player>  $p
 * @param  GameMatchFactory|array<string, mixed>  $match  a factory state, or attributes for a plain match
 */
function winnerMatch(PlaySession $session, array $p, GameMatchFactory|array $match, int $minutesAgo = 0): GameMatch
{
    $factory = is_array($match) ? GameMatch::factory()->state($match) : $match;
    $match = $factory->for($session)->create([
        'started_at' => now()->subMinutes($minutesAgo + 12),
        'finished_at' => now()->subMinutes($minutesAgo),
    ]);
    foreach ([[$p['Ann'], Team::A, 1], [$p['Bob'], Team::A, 2], [$p['Cy'], Team::B, 1], [$p['Di'], Team::B, 2]] as [$player, $team, $slot]) {
        MatchPlayer::create(['match_id' => $match->id, 'player_id' => $player->id, 'team' => $team, 'slot' => $slot]);
    }

    return $match;
}

/** @return array<string, array{played: int, wins: int, diff: int}> */
function standingsByName(PlaySession $session): array
{
    $out = [];
    foreach (app(StatsService::class)->sessionStandings($session) as $r) {
        $out[$r->row->name] = ['played' => $r->row->played, 'wins' => $r->row->wins, 'diff' => $r->row->pointDiff()];
    }

    return $out;
}

test('standings count every winner-only match for played and wins, and points only from the scored one', function () {
    [, $session] = winnerWorld();

    $rows = standingsByName($session);

    // Ann & Bob won 2 of 3 and Cy & Di won 1. Point diff comes only from the 11-5 match.
    expect($rows['Ann'])->toBe(['played' => 3, 'wins' => 2, 'diff' => 6])
        ->and($rows['Bob'])->toBe(['played' => 3, 'wins' => 2, 'diff' => 6])
        ->and($rows['Cy'])->toBe(['played' => 3, 'wins' => 1, 'diff' => -6])
        ->and($rows['Di'])->toBe(['played' => 3, 'wins' => 1, 'diff' => -6]);
});

test('a done match without a winner never counts, and void and staged matches never count', function () {
    [, $session, $p] = winnerWorld();
    winnerMatch($session, $p, ['status' => MatchStatus::Done, 'winner' => null, 'team_a_score' => 11, 'team_b_score' => 2]);
    winnerMatch($session, $p, ['status' => MatchStatus::Void, 'winner' => null]);
    winnerMatch($session, $p, ['status' => MatchStatus::Staged, 'winner' => null]);

    expect(standingsByName($session)['Ann']['played'])->toBe(3);
});

test('the leaderboard and the public leaderboard use winner for win percent', function () {
    [$club] = winnerWorld();
    $stats = app(StatsService::class);

    $board = $stats->leaderboard($club);
    $byName = [];
    foreach ($board['ranked'] as $r) {
        $byName[$r->row->name] = ['rank' => $r->rank, 'played' => $r->row->played, 'wins' => $r->row->wins, 'pct' => $r->row->winPercent()];
    }
    expect($byName['Ann'])->toBe(['rank' => 1, 'played' => 3, 'wins' => 2, 'pct' => 67])
        ->and($byName['Cy']['wins'])->toBe(1);

    $public = $stats->publicLeaderboard($club);
    expect(collect($public['ranked'])->firstWhere('name', 'Ann'))->toMatchArray(['played' => 3, 'wins' => 2, 'losses' => 1, 'win_pct' => 67, 'point_diff' => 6]);
});

test('a player profile counts winner-only matches and takes points from scored ones', function () {
    [$club, , $p] = winnerWorld();
    $stats = app(StatsService::class);

    $profile = $stats->profile($club, $p['Ann']);
    expect($profile['record'])->toMatchArray(['played' => 3, 'wins' => 2, 'losses' => 1, 'win_pct' => 67, 'points_for' => 11, 'points_against' => 5, 'point_diff' => 6])
        ->and(collect($profile['partners'])->firstWhere('name', 'Bob'))->toMatchArray(['games' => 3, 'wins' => 2])
        ->and(collect($profile['opponents'])->firstWhere('name', 'Cy'))->toMatchArray(['games' => 3, 'wins' => 2, 'losses' => 1]);

    $history = $stats->matchHistory($club, $p['Ann'], StatsPeriod::AllTime)->items();
    expect($history)->toHaveCount(3)
        ->and(array_column($history, 'won'))->toContain(true, false)
        ->and(collect($history)->filter(fn (array $h) => $h['score_for'] === null)->count())->toBe(2)
        ->and(collect($history)->firstWhere('score_for', 11))->toMatchArray(['score_against' => 5, 'won' => true]);
});

test('the match log carries the winner and null scores for winner-only matches', function () {
    [, $session] = winnerWorld();

    $log = app(StatsService::class)->matchLog($session);

    expect(array_column($log, 'winner'))->toBe(['A', 'B', 'A'])
        ->and(array_column($log, 'score_a'))->toBe([11, null, null]);
});

test('session wins on the board use winner, not scores', function () {
    [, $session, $p] = winnerWorld();

    $wins = app(SessionBoard::class)->winsByPlayer($session);

    expect($wins[$p['Ann']->id])->toBe(2)
        ->and($wins[$p['Bob']->id])->toBe(2)
        ->and($wins[$p['Cy']->id])->toBe(1)
        ->and($wins[$p['Di']->id])->toBe(1);
});

test('match history rows carry the winner with null scores for a winner-only match', function () {
    [, $session] = winnerWorld();

    $rows = app(SessionBoard::class)->recent($session);

    expect(array_column($rows, 'winner'))->toBe(['A', 'B', 'A'])
        ->and(array_column($rows, 'score_a'))->toBe([null, null, 11])
        ->and(array_column($rows, 'score_b'))->toBe([null, null, 5])
        ->and(array_column($rows, 'number'))->toBe([3, 2, 1]);
});

test('results count winner-only matches in totals and the podium, and points only from scored ones', function () {
    [, $session] = winnerWorld();

    $results = app(SessionResultsService::class)->staffResults($session);

    expect($results['totals']['matches'])->toBe(3)
        ->and($results['totals']['points'])->toBe(16)
        ->and($results['podium'])->toHaveCount(3)
        ->and($results['standings'][0]['wins'])->toBe(2)
        ->and($results['highlights']['biggest_win'])->toMatchArray(['score_a' => 11, 'score_b' => 5, 'margin' => 6])
        ->and($results['highlights']['closest_match']['margin'])->toBe(6)
        ->and(array_column($results['matches'], 'winner'))->toBe(['A', 'B', 'A']);
});

test('a session with only winner-only matches still gets a podium and no score highlights', function () {
    [, $session, $p] = winnerWorld();
    GameMatch::query()->where('play_session_id', $session->id)->update(['team_a_score' => null, 'team_b_score' => null]);

    $results = app(SessionResultsService::class)->staffResults($session);

    expect($results['totals']['matches'])->toBe(3)
        ->and($results['totals']['points'])->toBe(0)
        ->and($results['podium'])->not->toBeEmpty()
        ->and($results['highlights']['closest_match'])->toBeNull()
        ->and($results['highlights']['biggest_win'])->toBeNull();
});

test('the public results and sessions list count winner-only matches', function () {
    [$club, $session] = winnerWorld();
    $service = app(SessionResultsService::class);

    $public = $service->publicResults($club, $session);
    expect($public['totals']['matches'])->toBe(3)
        ->and($public['standings'][0]['wins'])->toBe(2)
        ->and($public['matches'])->toHaveCount(3);

    $list = $service->publicSessions($club);
    expect($list['sessions'][0]['matches'])->toBe(3)
        ->and($list['sessions'][0]['top_player'])->not->toBeNull();
});

test('the public queue snapshot shows session wins from winner-only matches', function () {
    [$club, $session, $p] = winnerWorld();
    $session->forceFill(['status' => 'live', 'ended_at' => null])->save();

    $snapshot = app(PublicSessionView::class)->snapshot($session->fresh());

    $wins = collect($snapshot['players'])->pluck('wins', 'name');
    expect($wins['Ann'])->toBe(2)->and($wins['Cy'])->toBe(1);
});

test('DUPR eligibility skips a winner-only match as incomplete and keeps scored ones', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->ended()->create();
    $players = Player::factory()->count(4)->for($club)->sequence(
        ['dupr_id' => 'AAA111'], ['dupr_id' => 'AAA222'], ['dupr_id' => 'AAA333'], ['dupr_id' => 'AAA444'],
    )->create();
    $p = ['Ann' => $players[0], 'Bob' => $players[1], 'Cy' => $players[2], 'Di' => $players[3]];
    $scored = winnerMatch($session, $p, GameMatch::factory()->done(Team::A, 11, 5));
    $winnerOnly = winnerMatch($session, $p, GameMatch::factory()->winnerOnly(Team::B));

    $summary = app(DuprEligibility::class)->summarize($session);

    expect($summary->eligible->pluck('id')->all())->toBe([$scored->id])
        ->and($summary->skippedCount())->toBe(1)
        ->and($summary->skipped[0]->match->id)->toBe($winnerOnly->id)
        ->and($summary->skipped[0]->reason)->toBe(DuprSkipReason::Incomplete);
});
