<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function mService(): MatchService
{
    return app(MatchService::class);
}

test('four waiting players stage one Up Next match with teams and slots and no court', function () {
    [$session, $players] = board(3);
    expect(stagedOf($session))->toHaveCount(0);

    app(CheckInService::class)->checkIn($session, Player::factory()->for($session->club)->manual(3)->create());
    $staged = stagedOf($session);

    expect($staged)->toHaveCount(1)
        ->and($staged[0]->court_no)->toBeNull()
        ->and($staged[0]->matchPlayers)->toHaveCount(4)
        ->and($staged[0]->matchPlayers->where('team', Team::A)->pluck('slot')->sort()->values()->all())->toBe([1, 2])
        ->and($staged[0]->matchPlayers->where('team', Team::B)->pluck('slot')->sort()->values()->all())->toBe([1, 2]);
});

test('staging fills up to up_next_count and excludes players already staged', function () {
    [$session] = board(9, ['up_next_count' => 2]);

    $staged = stagedOf($session);
    expect($staged)->toHaveCount(2);
    expect(array_intersect(matchIds($staged[0]), matchIds($staged[1])))->toBe([]);
});

test('lowering up_next_count voids the newest surplus staged match', function () {
    [$session] = board(8, ['up_next_count' => 2]);
    $staged = stagedOf($session);

    app(PlaySessionService::class)->update($session, ['up_next_count' => 1]);

    expect($staged[0]->fresh()->status)->toBe(MatchStatus::Staged)
        ->and($staged[1]->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(1);
});

test('raising up_next_count stages more', function () {
    [$session] = board(8);
    expect(stagedOf($session))->toHaveCount(1);

    app(PlaySessionService::class)->update($session, ['up_next_count' => 2]);

    expect(stagedOf($session))->toHaveCount(2);
});

test('starting a match takes the lowest free court, marks players playing and restages', function () {
    [$session] = board(8);
    $first = stagedOf($session)[0];

    mService()->startMatch($session, $first);

    expect($first->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($first->fresh()->court_no)->toBe(1)
        ->and($first->fresh()->started_at)->not->toBeNull()
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->where('status', SessionPlayerStatus::Playing->value)->count())->toBe(4)
        ->and(stagedOf($session))->toHaveCount(1);

    $second = stagedOf($session)[0];
    expect(fn () => mService()->startMatch($session, $second, 1))->toThrow(ValidationException::class);
    expect(fn () => mService()->startMatch($session, $second, 9))->toThrow(ValidationException::class);
    mService()->startMatch($session, $second);
    expect($second->fresh()->court_no)->toBe(2);
});

test('starting is blocked when no court is free', function () {
    [$session] = board(8, ['courts' => 1]);
    mService()->startMatch($session, stagedOf($session)[0]);

    expect(fn () => mService()->startMatch($session, stagedOf($session)[0]))->toThrow(ValidationException::class);
});

test('auto_fill starts the oldest staged match on every free court and refills Up Next', function () {
    $club = Club::factory()->create();
    $session = app(PlaySessionService::class)->create($club, ['courts' => 2, 'auto_fill' => true]);
    for ($i = 0; $i < 12; $i++) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($club)->manual(3)->create());
    }
    expect(GameMatch::query()->count())->toBe(0);

    app(PlaySessionService::class)->start($session);

    $playing = GameMatch::query()->where('status', MatchStatus::Playing->value)->orderBy('court_no')->pluck('court_no')->all();
    expect($playing)->toBe([1, 2])
        ->and(stagedOf($session))->toHaveCount(1);
});

test('without auto_fill nothing starts by itself but Up Next is still staged', function () {
    [$session] = board(4);

    expect(stagedOf($session))->toHaveCount(1)
        ->and(GameMatch::query()->where('status', MatchStatus::Playing->value)->count())->toBe(0);
});

test('draft sessions stage nothing', function () {
    $session = PlaySession::factory()->create();
    for ($i = 0; $i < 4; $i++) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($session->club)->manual(3)->create());
    }

    expect(GameMatch::query()->count())->toBe(0);
});

test('a player who leaves a staged match voids it and the slot is re-staged', function () {
    [$session, $players] = board(8);
    $staged = stagedOf($session)[0];
    $leaving = Player::query()->findOrFail(matchIds($staged)[0]);

    app(CheckInService::class)->checkOut($session, $leaving);

    expect($staged->fresh()->status)->toBe(MatchStatus::Void);
    $new = stagedOf($session);
    expect($new)->toHaveCount(1)
        ->and(matchIds($new[0]))->not->toContain($leaving->id);
});

test('finish records the score, counts the game, restarts the wait and frees the court', function () {
    [$session] = board(8);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    $ids = matchIds($m);

    mService()->finish($session, $m, 11, 7);

    $fresh = $m->fresh();
    expect($fresh->status)->toBe(MatchStatus::Done)
        ->and([$fresh->team_a_score, $fresh->team_b_score])->toBe([11, 7])
        ->and($fresh->finished_at)->not->toBeNull();
    foreach ($ids as $id) {
        $e = SessionPlayer::query()->where('player_id', $id)->where('play_session_id', $session->id)->first();
        expect($e->games_played)->toBe(1)->and($e->status)->toBe(SessionPlayerStatus::Waiting)
            ->and($e->last_finished_at)->not->toBeNull();
    }
    // The court is free again: the next staged match can take court 1.
    mService()->startMatch($session, stagedOf($session)[0]);
    expect(GameMatch::query()->where('status', MatchStatus::Playing->value)->value('court_no'))->toBe(1);
});

test('finish rejects invalid scores and non-playing matches', function () {
    [$session] = board(8);
    $m = stagedOf($session)[0];

    expect(fn () => mService()->finish($session, $m, 11, 5))->toThrow(ValidationException::class);

    mService()->startMatch($session, $m);
    foreach ([[11, 10], [13, 10], [10, 8], [11, 11]] as [$a, $b]) {
        expect(fn () => mService()->finish($session, $m, $a, $b))->toThrow(ValidationException::class);
    }
    expect($m->fresh()->status)->toBe(MatchStatus::Playing);

    mService()->finish($session, $m, 12, 10);
    expect(fn () => mService()->finish($session, $m, 11, 3))->toThrow(ValidationException::class);
});

test('finish uses the session scoring config', function () {
    [$session] = board(4, ['scoring' => ['type' => 'side_out', 'games' => 1, 'to' => 15, 'win_by' => 1]]);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);

    expect(fn () => mService()->finish($session, $m, 11, 3))->toThrow(ValidationException::class);
    mService()->finish($session, $m, 15, 14);
    expect($m->fresh()->status)->toBe(MatchStatus::Done);
});

test('auto_fill starts the next match when a court frees', function () {
    [$session] = board(8, ['courts' => 1, 'auto_fill' => true]);
    $first = GameMatch::query()->where('status', MatchStatus::Playing->value)->firstOrFail();

    mService()->finish($session, $first, 11, 4);

    $playing = GameMatch::query()->where('status', MatchStatus::Playing->value)->get();
    expect($playing)->toHaveCount(1)->and($playing[0]->id)->not->toBe($first->id)->and($playing[0]->court_no)->toBe(1);
});

test('undo last result reverts to playing when the court and players are free', function () {
    [$session] = board(8);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    mService()->finish($session, $m, 11, 6);

    mService()->undoLast($session);

    $fresh = $m->fresh();
    expect($fresh->status)->toBe(MatchStatus::Playing)->and($fresh->team_a_score)->toBeNull()->and($fresh->finished_at)->toBeNull();
    foreach (matchIds($m) as $id) {
        $e = SessionPlayer::query()->where('player_id', $id)->first();
        expect($e->games_played)->toBe(0)->and($e->status)->toBe(SessionPlayerStatus::Playing);
    }
});

test('undo after the four were re-staged voids that staged match and does not re-stage them', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    mService()->finish($session, $m, 11, 6);
    $restaged = stagedOf($session);
    expect($restaged)->toHaveCount(1);

    mService()->undoLast($session);

    expect($m->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($restaged[0]->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(0);
});

test('undo is blocked when the court is occupied', function () {
    [$s2] = board(8);
    $a = stagedOf($s2)[0];
    mService()->startMatch($s2, $a);
    mService()->finish($s2, $a, 11, 6);
    mService()->startMatch($s2, stagedOf($s2)[0], 1);

    expect(fn () => mService()->undoLast($s2))->toThrow(ValidationException::class);
});

test('undo is blocked when one of the players is playing or has left', function () {
    [$session] = board(8);
    $m1 = stagedOf($session)[0];
    mService()->startMatch($session, $m1);
    mService()->finish($session, $m1, 11, 6);
    $m2 = stagedOf($session)[0];
    mService()->startMatch($session, $m2, 2);
    $out = Player::query()->findOrFail(matchIds($m2)[0]);
    $in = Player::query()->findOrFail(matchIds($m1)[0]);
    // Put a finished player into the playing match directly (re-staging would otherwise block a swap).
    MatchPlayer::query()->where('match_id', $m2->id)->where('player_id', $out->id)->update(['player_id' => $in->id]);
    SessionPlayer::query()->where('player_id', $in->id)->update(['status' => SessionPlayerStatus::Playing->value]);

    expect(fn () => mService()->undoLast($session))->toThrow(ValidationException::class);

    [$s3, $p3] = board(4);
    $b = stagedOf($s3)[0];
    mService()->startMatch($s3, $b);
    mService()->finish($s3, $b, 11, 6);
    app(CheckInService::class)->checkOut($s3, $p3[0]);
    expect(fn () => mService()->undoLast($s3))->toThrow(ValidationException::class);
});

test('undo with nothing finished is rejected', function () {
    [$s4] = board(2);
    expect(fn () => mService()->undoLast($s4))->toThrow(ValidationException::class);
});

test('edit score works on any done match until DUPR exported', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);

    expect(fn () => mService()->editScore($session, $m, 11, 5))->toThrow(ValidationException::class);

    mService()->finish($session, $m, 11, 5);
    mService()->editScore($session, $m, 9, 11);
    expect([$m->fresh()->team_a_score, $m->fresh()->team_b_score])->toBe([9, 11]);
    expect(fn () => mService()->editScore($session, $m, 11, 10))->toThrow(ValidationException::class);

    $m->forceFill(['dupr_exported_at' => now()])->save();
    expect(fn () => mService()->editScore($session, $m, 11, 3))->toThrow(ValidationException::class);
    expect(fn () => mService()->void($session, $m))->toThrow(ValidationException::class);
});

test('swap exchanges a player in a playing match with a waiting one', function () {
    [$session] = board(9);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    $out = Player::query()->findOrFail(matchIds($m)[0]);
    $in = Player::query()->whereIn('id', SessionPlayer::query()->where('status', 'waiting')->pluck('player_id'))
        ->whereNotIn('id', MatchPlayer::query()->whereIn('match_id', GameMatch::query()->whereIn('status', ['staged', 'playing'])->select('id'))->select('player_id'))
        ->firstOrFail();

    mService()->swap($session, $m, $out, $in);

    expect(matchIds($m))->toContain($in->id)->not->toContain($out->id)
        ->and(entryOf($session, $out)->status)->toBe(SessionPlayerStatus::Waiting)
        ->and(entryOf($session, $in)->status)->toBe(SessionPlayerStatus::Playing);
});

test('swap rejects a player in another match, a non-waiting player, and a foreign player', function () {
    [$session] = board(8);
    $staged = stagedOf($session)[0];
    mService()->startMatch($session, $staged);
    $second = stagedOf($session)[0];
    $out = Player::query()->findOrFail(matchIds($staged)[0]);
    $inOther = Player::query()->findOrFail(matchIds($second)[0]);

    expect(fn () => mService()->swap($session, $staged, $out, $inOther))->toThrow(ValidationException::class);

    $foreign = Player::factory()->create();
    expect(fn () => mService()->swap($session, $staged, $out, $foreign))->toThrow(ValidationException::class);

    $notInMatch = Player::query()->findOrFail(matchIds($second)[1]);
    expect(fn () => mService()->swap($session, $staged, $notInMatch, $inOther))->toThrow(ValidationException::class);
    expect(matchIds($staged))->toContain($out->id);
});

test('swap works on a staged match without changing statuses', function () {
    [$session] = board(9);
    $m = stagedOf($session)[0];
    $out = Player::query()->findOrFail(matchIds($m)[0]);
    $in = Player::query()->whereNotIn('id', matchIds($m))->firstOrFail();

    mService()->swap($session, $m, $out, $in);

    expect(matchIds($m))->toContain($in->id)->and(entryOf($session, $in)->status)->toBe(SessionPlayerStatus::Waiting);
});

test('remove fills the slot with a waiting player and sends the removed player to break or left', function (SessionPlayerStatus $status) {
    [$session] = board(9);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    $removed = Player::query()->findOrFail(matchIds($m)[1]);

    mService()->remove($session, $m, $removed, $status);

    $ids = matchIds($m);
    expect($ids)->toHaveCount(4)->not->toContain($removed->id)
        ->and(entryOf($session, $removed)->status)->toBe($status)
        ->and(entryOf($session, $removed)->games_played)->toBe(0);
    expect(SessionPlayer::query()->whereIn('player_id', $ids)->where('status', 'playing')->count())->toBe(4);
})->with([SessionPlayerStatus::Waiting, SessionPlayerStatus::Break, SessionPlayerStatus::Left]);

test('remove is blocked when nobody is available', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    $removed = Player::query()->findOrFail(matchIds($m)[0]);

    expect(fn () => mService()->remove($session, $m, $removed))->toThrow(ValidationException::class);

    expect(matchIds($m))->toContain($removed->id)
        ->and(entryOf($session, $removed)->status)->toBe(SessionPlayerStatus::Playing);
});

test('void a staged match frees players and restages', function () {
    [$session] = board(8);
    $m = stagedOf($session)[0];

    mService()->void($session, $m);

    expect($m->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(1)
        ->and(stagedOf($session)[0]->id)->not->toBe($m->id);
});

test('void a playing match sends players back to waiting, frees the court and counts no game', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);

    mService()->void($session, $m);

    expect($m->fresh()->status)->toBe(MatchStatus::Void)->and($m->fresh()->court_no)->toBeNull();
    foreach (matchIds($m) as $id) {
        $e = SessionPlayer::query()->where('player_id', $id)->first();
        expect($e->status)->toBe(SessionPlayerStatus::Waiting)->and($e->games_played)->toBe(0);
    }
});

test('void a done match decrements games played', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    mService()->finish($session, $m, 11, 2);

    mService()->void($session, $m);

    expect($m->fresh()->status)->toBe(MatchStatus::Void);
    foreach (matchIds($m) as $id) {
        expect(SessionPlayer::query()->where('player_id', $id)->first()->games_played)->toBe(0);
    }
    expect(fn () => mService()->void($session, $m))->toThrow(ValidationException::class);
});

test('re-roll voids a staged match and stages a different group', function () {
    [$session] = board(8);
    $m = stagedOf($session)[0];
    $before = matchIds($m);

    mService()->reroll($session, $m);

    expect($m->fresh()->status)->toBe(MatchStatus::Void);
    $new = stagedOf($session);
    expect($new)->toHaveCount(1)->and(matchIds($new[0]))->not->toBe($before);
    expect(fn () => mService()->reroll($session, $m))->toThrow(ValidationException::class);
});

test('re-roll with six waiting players produces a different group', function () {
    [$session] = board(6);
    $m = stagedOf($session)[0];
    $before = matchIds($m);

    mService()->reroll($session, $m);

    $new = stagedOf($session);
    expect($new)->toHaveCount(1)->and(matchIds($new[0]))->not->toBe($before)
        ->and(count(array_intersect(matchIds($new[0]), $before)))->toBeGreaterThanOrEqual(2);
});

test('re-roll can still choose old top-priority players', function () {
    [$session] = board(5);
    $m = stagedOf($session)[0];
    $before = matchIds($m);

    mService()->reroll($session, $m);

    // Only one player was outside the old group, so at least three old players come back.
    $new = stagedOf($session);
    expect(matchIds($new[0]))->not->toBe($before)
        ->and(count(array_intersect(matchIds($new[0]), $before)))->toBeGreaterThanOrEqual(3);
});

test('re-roll with nobody else available re-stages the same group', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    $before = matchIds($m);

    mService()->reroll($session, $m);

    $new = stagedOf($session);
    expect($m->fresh()->status)->toBe(MatchStatus::Void)->and($new)->toHaveCount(1)->and(matchIds($new[0]))->toBe($before);
});

function teamSets(GameMatch $m): array
{
    $sets = $m->matchPlayers->groupBy(fn ($mp) => $mp->team->value)
        ->map(fn ($g) => $g->pluck('player_id')->map(fn ($i) => (int) $i)->sort()->values()->implode('-'))
        ->values()->all();
    sort($sets);

    return $sets;
}

test('repeat history alone separates the splits of the same four players', function () {
    // Equal stars, equal games, one possible group: only the partner/opponent history term
    // can make the second match use different teams from the first.
    [$session] = board(4);
    $first = stagedOf($session)[0];
    $firstTeams = teamSets($first);
    mService()->startMatch($session, $first);
    mService()->finish($session, $first, 11, 3);

    $second = stagedOf($session)[0]->load('matchPlayers');
    expect(matchIds($second))->toBe(matchIds($first))
        ->and(teamSets($second))->not->toBe($firstTeams);
});

test('undo restores timestamps from the previous done match or check-in', function () {
    [$session] = board(4);
    $m1 = stagedOf($session)[0];
    mService()->startMatch($session, $m1);
    mService()->finish($session, $m1, 11, 3);
    $m1Finished = $m1->fresh()->finished_at->getTimestamp();

    $this->travel(30)->seconds();
    $m2 = stagedOf($session)[0];
    mService()->startMatch($session, $m2);
    mService()->finish($session, $m2, 11, 4);

    mService()->undoLast($session);

    foreach (matchIds($m2) as $id) {
        $e = SessionPlayer::query()->where('player_id', $id)->firstOrFail();
        expect($e->last_finished_at->getTimestamp())->toBe($m1Finished)
            ->and($e->queued_at->getTimestamp())->toBe($m1Finished);
    }

    // No prior done match: falls back to check-in time.
    [$s2] = board(4);
    $only = stagedOf($s2)[0];
    mService()->startMatch($s2, $only);
    mService()->finish($s2, $only, 11, 3);
    mService()->undoLast($s2);
    foreach (matchIds($only) as $id) {
        $e = SessionPlayer::query()->where('player_id', $id)->where('play_session_id', $s2->id)->firstOrFail();
        expect($e->last_finished_at)->toBeNull()
            ->and($e->queued_at->getTimestamp())->toBe($e->checked_in_at->getTimestamp());
    }
});

test('undo is blocked when a player is on break', function () {
    [$session, $players] = board(4);
    $m = stagedOf($session)[0];
    mService()->startMatch($session, $m);
    mService()->finish($session, $m, 11, 6);
    app(CheckInService::class)->goOnBreak($session, $players[0]);

    expect(fn () => mService()->undoLast($session))->toThrow(ValidationException::class);
});

test('a stale match instance cannot start or finish twice', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    $stale = GameMatch::query()->findOrFail($m->id);

    mService()->startMatch($session, $m);
    expect(fn () => mService()->startMatch($session, $stale))->toThrow(ValidationException::class);
    expect(GameMatch::query()->where('status', MatchStatus::Playing->value)->count())->toBe(1);

    $staleFinish = GameMatch::query()->findOrFail($m->id);
    mService()->finish($session, $m, 11, 3);
    expect(fn () => mService()->finish($session, $staleFinish, 11, 3))->toThrow(ValidationException::class);
    foreach (matchIds($m) as $id) {
        expect(SessionPlayer::query()->where('player_id', $id)->firstOrFail()->games_played)->toBe(1);
    }
});

test('a match from another session or club is rejected', function () {
    [$a] = board(4);
    [$b] = board(4);
    $foreign = stagedOf($b)[0];

    expect(fn () => mService()->startMatch($a, $foreign))->toThrow(ValidationException::class);
    expect(fn () => mService()->void($a, $foreign))->toThrow(ValidationException::class);
    expect(fn () => mService()->reroll($a, $foreign))->toThrow(ValidationException::class);
    expect($foreign->fresh()->status)->toBe(MatchStatus::Staged);
});

test('board actions need a live session', function () {
    [$session] = board(4);
    $m = stagedOf($session)[0];
    app(PlaySessionService::class)->end($session);

    expect(fn () => mService()->startMatch($session->fresh(), $m))->toThrow(ValidationException::class);
});

test('ending the session voids staged matches and does not restage', function () {
    [$session] = board(8);

    app(PlaySessionService::class)->end($session);

    expect(stagedOf($session))->toHaveCount(0);
});

test('create merges a partial scoring array with the defaults', function () {
    $club = Club::factory()->create();

    $session = app(PlaySessionService::class)->create($club, ['scoring' => ['to' => 15]]);

    expect($session->scoring)->toBe(['type' => 'side_out', 'games' => 1, 'to' => 15, 'win_by' => 2]);
});

test('start locks the session players before any plain read', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Row locks are not issued on SQLite.');
    }

    $club = Club::factory()->create(['allow_concurrent_sessions' => true]);
    $session = PlaySession::factory()->for($club)->create();
    app(CheckInService::class)->checkIn($session, Player::factory()->for($club)->create());

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    app(PlaySessionService::class)->start($session);

    $selects = array_values(array_filter($queries, fn ($q) => str_starts_with(strtolower($q), 'select')));
    $playerLock = null;
    foreach ($selects as $i => $sql) {
        if (str_contains($sql, 'from `players`') && str_contains($sql, 'for update')) {
            $playerLock = $i;
            break;
        }
    }
    expect($playerLock)->not->toBeNull();
    foreach (array_slice($selects, 0, $playerLock) as $sql) {
        expect($sql)->toContain('for update');
    }
});
