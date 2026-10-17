<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/** Assert that no player is in more than one open (staged or playing) match of the session. */
function expectOneOpenMatchEach(PlaySession $session): void
{
    $rows = DB::table('match_players as mp')
        ->join('matches as m', 'm.id', '=', 'mp.match_id')
        ->where('m.play_session_id', $session->id)
        ->whereIn('m.status', [MatchStatus::Staged->value, MatchStatus::Playing->value])
        ->selectRaw('mp.player_id, count(*) as c')
        ->groupBy('mp.player_id')
        ->pluck('c');

    expect($rows->max() ?? 0)->toBeLessThanOrEqual(1);
}

/** Every open match holds exactly 4 players. */
function expectOpenMatchesFull(PlaySession $session): void
{
    foreach (GameMatch::query()->where('play_session_id', $session->id)->whereIn('status', ['staged', 'playing'])->get() as $match) {
        expect($match->matchPlayers()->count())->toBe(4);
    }
}

// --- swap from waiting (unchanged behaviour) ---

test('swapping in a free waiting player leaves every other Up Next match alone', function () {
    [$session] = board(13, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::query()->whereNotIn('id', [...matchIds($first), ...matchIds($second)])->firstOrFail();
    $secondBefore = matchIds($second);

    app(MatchService::class)->swap($session, $first, $out, $in);

    expect(matchIds($first->fresh()))->toContain($in->id)->not->toContain($out->id)
        ->and(matchIds($second->fresh()))->toBe($secondBefore)
        ->and(entryOf($session, $out)->status)->toBe(SessionPlayerStatus::Waiting);
    expectOneOpenMatchEach($session);
});

// --- swap from Up Next ---

test('swapping in an Up Next player refills their match with the best waiting player', function () {
    [$session] = board(13, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::findOrFail(matchIds($second)[0]);
    $secondRest = array_values(array_diff(matchIds($second), [$in->id]));

    app(MatchService::class)->swap($session, $first, $out, $in);

    $firstIds = matchIds($first->fresh());
    $secondIds = matchIds($second->fresh());
    expect($firstIds)->toContain($in->id)->not->toContain($out->id)->toHaveCount(4)
        ->and($second->fresh()->status)->toBe(MatchStatus::Staged)
        ->and($secondIds)->toHaveCount(4)->not->toContain($in->id)
        ->and(array_intersect($secondRest, $secondIds))->toHaveCount(3)
        ->and(entryOf($session, $in)->status)->toBe(SessionPlayerStatus::Waiting);
    expectOneOpenMatchEach($session);
    expectOpenMatchesFull($session);
});

test('the swapped-out player can be the one who refills the Up Next match', function () {
    [$session] = board(8, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::findOrFail(matchIds($second)[0]);

    app(MatchService::class)->swap($session, $first, $out, $in);

    // Nobody else waits, so the player who left the first match takes the slot the other one left.
    expect(matchIds($first->fresh()))->toContain($in->id)->not->toContain($out->id)
        ->and($second->fresh()->status)->toBe(MatchStatus::Staged)
        ->and(matchIds($second->fresh()))->toContain($out->id)->not->toContain($in->id)->toHaveCount(4);
    expectOneOpenMatchEach($session);
});

test('swapping an Up Next player into a playing match refills their Up Next match', function () {
    [$session] = board(12, ['up_next_count' => 2, 'courts' => 1]);
    $first = stagedOf($session)[0];
    app(MatchService::class)->startMatch($session, $first);
    $second = stagedOf($session)[0];
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::findOrFail(matchIds($second)[0]);

    app(MatchService::class)->swap($session, $first->fresh(), $out, $in);

    expect(entryOf($session, $in)->status)->toBe(SessionPlayerStatus::Playing)
        ->and(entryOf($session, $out)->status)->not->toBe(SessionPlayerStatus::Playing)
        ->and(matchIds($first->fresh()))->toContain($in->id)->not->toContain($out->id)
        ->and($second->fresh()->status)->toBe(MatchStatus::Staged)
        ->and(matchIds($second->fresh()))->toHaveCount(4)->not->toContain($in->id);
    expectOneOpenMatchEach($session);
    expectOpenMatchesFull($session);
});

test('a player on a court, in the match being edited, on break or from another session is refused', function () {
    [$session] = board(12, ['up_next_count' => 1, 'courts' => 2]);
    $a = stagedOf($session)[0];
    app(MatchService::class)->startMatch($session, $a);
    $b = stagedOf($session)[0];
    app(MatchService::class)->startMatch($session, $b);
    $out = Player::findOrFail(matchIds($a->fresh())[0]);

    $onCourt = Player::findOrFail(matchIds($b->fresh())[0]);
    expect(fn () => app(MatchService::class)->swap($session, $a->fresh(), $out, $onCourt))->toThrow(ValidationException::class);

    $mate = Player::findOrFail(matchIds($a->fresh())[1]);
    expect(fn () => app(MatchService::class)->swap($session, $a->fresh(), $out, $mate))->toThrow(ValidationException::class);

    $breaker = Player::query()->whereNotIn('id', [...matchIds($a->fresh()), ...matchIds($b->fresh())])->firstOrFail();
    SessionPlayer::query()->where('player_id', $breaker->id)->update(['status' => SessionPlayerStatus::Break->value]);
    expect(fn () => app(MatchService::class)->swap($session, $a->fresh(), $out, $breaker))->toThrow(ValidationException::class);

    expect(matchIds($a->fresh()))->toContain($out->id);
    expectOneOpenMatchEach($session);
});

test('a swap with an Up Next player dispatches the board event', function () {
    [$session] = board(9, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::findOrFail(matchIds($second)[0]);

    Event::fake([PlaySessionChanged::class]);
    app(MatchService::class)->swap($session, $first, $out, $in);

    Event::assertDispatched(PlaySessionChanged::class, fn ($e) => $e->playSessionId === $session->id);
});

// --- candidates ---

test('swap candidates list free waiting players first, then other Up Next players with their number', function () {
    [$session] = board(14, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $board = app(SessionBoard::class);

    $rows = $board->swapCandidates($session, $first->id);

    $waiting = array_values(array_filter($rows, fn (array $r) => $r['source'] === 'waiting'));
    $upNext = array_values(array_filter($rows, fn (array $r) => $r['source'] === 'up_next'));
    expect(array_column($rows, 'source'))->toBe([...array_fill(0, count($waiting), 'waiting'), ...array_fill(0, count($upNext), 'up_next')])
        ->and(array_column($waiting, 'id'))->toBe(array_column($board->waiting($session), 'id'))
        ->and($waiting)->toHaveCount(6)
        ->and(array_column($upNext, 'id'))->toHaveCount(4)
        ->and(array_values(array_unique(array_column($upNext, 'up_next_number'))))->toBe([2])
        ->and(array_values(array_unique(array_column($upNext, 'match_id'))))->toBe([$second->id])
        ->and(array_column($waiting, 'up_next_number'))->each->toBeNull();

    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'stars', 'gender', 'games_played', 'waited_minutes', 'source', 'up_next_number']);
    }

    // The same list from the other match numbers the first one as 1.
    $other = $board->swapCandidates($session, $second->id);
    $otherUp = array_values(array_filter($other, fn (array $r) => $r['source'] === 'up_next'));
    expect(array_values(array_unique(array_column($otherUp, 'up_next_number'))))->toBe([1])
        ->and(array_column($otherUp, 'id'))->toEqualCanonicalizing(matchIds($first));
});

test('swap candidates never include players on a court or in the match being edited', function () {
    [$session] = board(12, ['up_next_count' => 2, 'courts' => 1]);
    $playing = stagedOf($session)[0];
    app(MatchService::class)->startMatch($session, $playing);
    $staged = stagedOf($session);
    $board = app(SessionBoard::class);

    $forPlaying = array_column($board->swapCandidates($session, $playing->id), 'id');
    $forStaged = array_column($board->swapCandidates($session, $staged[0]->id), 'id');

    expect(array_intersect($forPlaying, matchIds($playing->fresh())))->toBe([])
        ->and(array_intersect($forStaged, matchIds($playing->fresh())))->toBe([])
        ->and(array_intersect($forStaged, matchIds($staged[0])))->toBe([])
        ->and($forPlaying)->toContain(...matchIds($staged[0]));
});

test('swap candidates are only the waiting list when there is no other Up Next match', function () {
    [$session] = board(6);
    $staged = stagedOf($session)[0];

    $rows = app(SessionBoard::class)->swapCandidates($session, $staged->id);

    expect(array_unique(array_column($rows, 'source')))->toBe(['waiting'])->and($rows)->toHaveCount(2);
});

// --- stale picks ---

/** @return array<string, list<string>> */
function swapErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('a player picked from the waiting list who was staged since is refused and nothing moves', function () {
    [$session] = board(13, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $moved = Player::findOrFail(matchIds($second)[0]);
    $secondBefore = matchIds($second);

    $errors = swapErrors(fn () => app(MatchService::class)->swap($session, $first, $out, $moved, verifySource: true, inMatchId: null));

    expect($errors['player'][0])->toBe('That player has moved. Pick again.')
        ->and(matchIds($second->fresh()))->toBe($secondBefore)
        ->and(matchIds($first->fresh()))->toContain($out->id);
});

test('a player picked from Up Next who is now waiting, or in a different match, is refused', function () {
    [$session] = board(13, ['up_next_count' => 3]);
    [$first, $second, $third] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $in = Player::findOrFail(matchIds($second)[0]);
    $thirdBefore = matchIds($third);

    // The client saw the player in the second match, but they are in the third now.
    $errors = swapErrors(fn () => app(MatchService::class)->swap($session, $first, $out, Player::findOrFail($thirdBefore[0]), verifySource: true, inMatchId: $second->id));
    expect($errors['player'][0])->toBe('That player has moved. Pick again.');

    // Voiding the second match puts its players back to waiting.
    app(MatchService::class)->void($session, $second);
    $errors = swapErrors(fn () => app(MatchService::class)->swap($session, $first->fresh(), $out, $in, verifySource: true, inMatchId: $second->id));
    expect($errors['player'][0])->toBe('That player has moved. Pick again.')
        ->and(matchIds($first->fresh()))->toContain($out->id)
        ->and(matchIds($third->fresh()))->toBe($thirdBefore);
});

test('a player who went on break since the pick is refused as moved', function () {
    [$session] = board(9);
    $staged = stagedOf($session)[0];
    $out = Player::findOrFail(matchIds($staged)[0]);
    $in = Player::query()->whereNotIn('id', matchIds($staged))->firstOrFail();
    SessionPlayer::query()->where('player_id', $in->id)->update(['status' => SessionPlayerStatus::Break->value]);

    expect(swapErrors(fn () => app(MatchService::class)->swap($session, $staged, $out, $in, verifySource: true, inMatchId: null))['player'][0])
        ->toBe('That player has moved. Pick again.');
});

test('a source that still matches what the client saw goes through', function () {
    [$session] = board(13, ['up_next_count' => 2]);
    [$first, $second] = stagedOf($session)->all();
    $out = Player::findOrFail(matchIds($first)[0]);
    $inUpNext = Player::findOrFail(matchIds($second)[0]);

    app(MatchService::class)->swap($session, $first, $out, $inUpNext, verifySource: true, inMatchId: $second->id, matchStatus: MatchStatus::Staged);
    expect(matchIds($first->fresh()))->toContain($inUpNext->id);

    $free = Player::findOrFail(app(SessionBoard::class)->waiting($session)[0]['id']);
    $out2 = Player::findOrFail(matchIds($first->fresh())[1]);
    app(MatchService::class)->swap($session, $first->fresh(), $out2, $free, verifySource: true, inMatchId: null, matchStatus: MatchStatus::Staged);
    expect(matchIds($first->fresh()))->toContain($free->id);
});

test('a target match that started since the client saw it staged is refused', function () {
    [$session] = board(9, ['up_next_count' => 1]);
    $staged = stagedOf($session)[0];
    $out = Player::findOrFail(matchIds($staged)[0]);
    $in = Player::findOrFail(app(SessionBoard::class)->waiting($session)[0]['id']);
    app(MatchService::class)->startMatch($session, $staged);

    $errors = swapErrors(fn () => app(MatchService::class)->swap($session, $staged->fresh(), $out, $in, matchStatus: MatchStatus::Staged));

    expect($errors['match'][0])->toBe('That match has changed. Reopen the swap.')
        ->and(matchIds($staged->fresh()))->toContain($out->id)
        ->and(entryOf($session, $out)->status)->toBe(SessionPlayerStatus::Playing);
});
