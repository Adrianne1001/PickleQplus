<?php

use App\Domain\Rotation\MatchResult;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use App\Services\Rotation\BalancedStrategy;
use App\Services\Rotation\RotationQueries;
use App\Services\Rotation\RotationStrategies;
use Illuminate\Validation\ValidationException;

function socialSession(array $attrs = []): PlaySession
{
    return PlaySession::factory()->for(Club::factory()->create())->live()->create([
        'rotation_mode' => RotationMode::Social,
        'courts' => 1,
        'up_next_count' => 1,
        'auto_fill' => true,
        ...$attrs,
    ]);
}

/** @return list<Player> */
function socialJoin(PlaySession $session, int $count): array
{
    $players = [];
    for ($i = 0; $i < $count; $i++) {
        $player = Player::factory()->for($session->club)->manual(($i % 6) + 1)->create();
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return $players;
}

/** @return list<GameMatch> */
function socialMatches(PlaySession $session, MatchStatus $status): array
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', $status->value)->orderBy('id')->get()->all();
}

test('social mix is selectable, listed after skill courts, and uses the balanced strategy', function () {
    $values = array_map(fn (RotationMode $m) => $m->value, RotationMode::selectable());

    expect(RotationMode::Social->label())->toBe('Social mix')
        ->and(array_slice($values, 0, 4))->toBe(['balanced', 'mixed', 'skill_courts', 'social'])
        ->and(RotationStrategies::for(PlaySession::factory()->make(['rotation_mode' => RotationMode::Social])))->toBeInstanceOf(BalancedStrategy::class);
});

test('a draft session saves with the social mode', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->create();

    app(PlaySessionService::class)->update($session, ['rotation_mode' => 'social']);

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Social);
});

test('a live social session stages Up Next and starts matches', function () {
    $session = socialSession();
    socialJoin($session, 8);

    $staged = socialMatches($session, MatchStatus::Playing);
    expect($staged)->toHaveCount(1);

    $up = socialMatches($session, MatchStatus::Staged);
    expect($up)->toHaveCount(1);
});

test('switching to and from social voids staged Up Next', function () {
    $session = socialSession(['rotation_mode' => RotationMode::Balanced]);
    socialJoin($session, 8);
    $first = socialMatches($session, MatchStatus::Staged)[0];

    app(PlaySessionService::class)->update($session->fresh(), ['rotation_mode' => 'social']);
    $second = socialMatches($session, MatchStatus::Staged)[0];
    expect($first->fresh()->status)->toBe(MatchStatus::Void)
        ->and($second->id)->not->toBe($first->id);

    app(PlaySessionService::class)->update($session->fresh(), ['rotation_mode' => 'balanced']);
    expect($second->fresh()->status)->toBe(MatchStatus::Void)
        ->and(socialMatches($session, MatchStatus::Staged))->toHaveCount(1);
});

test('removing a player from a staged social match brings in a replacement', function () {
    $session = socialSession(['courts' => 1]);
    socialJoin($session, 9);
    $match = socialMatches($session, MatchStatus::Staged)[0];
    $out = Player::findOrFail($match->matchPlayers()->first()->player_id);

    app(MatchService::class)->remove($session, $match, $out);

    $ids = $match->fresh()->matchPlayers()->pluck('player_id')->all();
    expect($ids)->toHaveCount(4)->not->toContain($out->id);
});

test('an unknown mode is still rejected', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->create();

    expect(fn () => app(PlaySessionService::class)->update($session, ['rotation_mode' => 'nope']))->toThrow(ValidationException::class);
});

/**
 * 4 players (6, 6, 1, 1 stars) who have already partnered 1+3, 2+4, 1+4 and 2+3 once. Returns the
 * staged match's teams (as sorted id pairs) after a refill in the given mode.
 *
 * @return array{0: list<list<int>>, 1: list<int>}
 */
function stagedTeamsAfterHistory(RotationMode $mode): array
{
    $session = socialSession(['rotation_mode' => $mode, 'up_next_count' => 0, 'auto_fill' => false]);
    $players = [];
    foreach ([6, 6, 1, 1] as $stars) {
        $player = Player::factory()->for($session->club)->manual($stars)->create();
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player->id;
        test()->travel(1)->seconds();
    }
    [$p1, $p2, $p3, $p4] = $players;

    foreach ([[[$p1, $p3], [$p2, $p4]], [[$p1, $p4], [$p2, $p3]]] as [$teamA, $teamB]) {
        $past = (new RotationQueries($session))->createStaged(new MatchResult($teamA, $teamB, 0.0, []));
        $past->status = MatchStatus::Done;
        $past->save();
    }
    // The four went back to waiting after that match.
    $session->forceFill(['up_next_count' => 1])->save();
    app(MatchService::class)->refill($session->fresh());

    $match = stagedOf($session)[0];
    $teams = $match->matchPlayers()->get()->groupBy(fn ($mp) => $mp->team->value)
        ->map(fn ($rows) => $rows->pluck('player_id')->map(fn ($i) => (int) $i)->sort()->values()->all())
        ->sort()->values()->all();

    return [$teams, $players];
}

test('the live flow uses social weights: stars are ignored and fresh partners win, unlike balanced', function () {
    [$social, $ids] = stagedTeamsAfterHistory(RotationMode::Social);
    [$balanced] = stagedTeamsAfterHistory(RotationMode::Balanced);

    // Balanced keeps a star-even split (6+1 v 6+1) and repeats both partnerships. Social ignores
    // stars and pairs the two 6s against the two 1s, the only fresh split.
    expect($social)->toBe([[$ids[0], $ids[1]], [$ids[2], $ids[3]]])
        ->and($balanced)->not->toBe($social);
});
