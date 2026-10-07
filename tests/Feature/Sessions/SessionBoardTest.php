<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\Results;
use App\Livewire\Sessions\UpNext;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A live session in a club with an owner, with $n players checked in through
 * the service so Up Next staging runs.
 *
 * @param  array<string, mixed>  $attrs
 * @return array{0: User, 1: Club, 2: PlaySession, 3: list<Player>}
 */
function liveBoard(int $n, array $attrs = []): array
{
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    $session = PlaySession::factory()->for($club)->live()->create($attrs);
    $players = [];
    for ($i = 0; $i < $n; $i++) {
        $player = Player::factory()->for($club)->manual(3)->create();
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return [$user, $club, $session->fresh(), $players];
}

function stagedMatch(PlaySession $session): GameMatch
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Staged->value)->orderBy('id')->firstOrFail();
}

function playingMatch(PlaySession $session): GameMatch
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->orderBy('id')->firstOrFail();
}

/** Start the first staged match and return it. */
function startFirst(PlaySession $session): GameMatch
{
    $match = stagedMatch($session);
    app(MatchService::class)->startMatch($session->fresh(), $match);

    return $match->fresh();
}

// --- access ---

test('strangers get 404 on every board panel and staff are allowed', function () {
    [, $club, $session] = liveBoard(4);
    $stranger = User::factory()->create();
    $staff = User::factory()->create();
    $club->users()->attach($staff->id, ['role' => 'staff']);

    foreach ([Courts::class, UpNext::class, WaitingList::class, Results::class] as $component) {
        Livewire::actingAs($stranger)->test($component, ['session' => $session])->assertNotFound();
        Livewire::actingAs($staff)->test($component, ['session' => $session])->assertOk();
    }

    $this->actingAs($staff)->get(route('clubs.sessions.show', [$club, $session]))
        ->assertOk()
        ->assertSee('data-test="courts-panel"', false)
        ->assertSee('data-test="up-next-panel"', false);
});

test('actions are denied after membership is removed', function () {
    [$user, $club, $session] = liveBoard(4);
    $component = Livewire::actingAs($user)->test(UpNext::class, ['session' => $session]);
    $match = stagedMatch($session);

    $club->users()->detach($user->id);
    User::flushRoleCache();

    $component->call('start', $match->id)->assertNotFound();
    expect($match->fresh()->status)->toBe(MatchStatus::Staged);
});

test('the board is only shown on a live session page', function () {
    [$user, $club] = liveBoard(0);
    $draft = PlaySession::factory()->for($club)->create();

    $this->actingAs($user)->get(route('clubs.sessions.show', [$club, $draft]))
        ->assertOk()
        ->assertDontSee('data-test="courts-panel"', false);
});

// --- Up Next ---

test('start puts the staged match on the lowest free court and restages', function () {
    [$user, , $session] = liveBoard(8);
    $match = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('start', $match->id)
        ->assertHasNoErrors()
        ->assertDispatched('board-changed');

    expect($match->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($match->fresh()->court_no)->toBe(1);
});

test('start on a chosen court', function () {
    [$user, , $session] = liveBoard(4);
    $match = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->set('courtChoice.0', '3')
        ->call('start', $match->id)
        ->assertHasNoErrors();

    expect($match->fresh()->court_no)->toBe(3);
});

test('start with no free court shows the error inline and disables the button', function () {
    [$user, , $session] = liveBoard(8, ['courts' => 1, 'up_next_count' => 1]);
    startFirst($session);
    $next = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->assertSee('No free court')
        ->call('start', $next->id)
        ->assertHasErrors('court')
        ->assertSee('No court is free');

    expect($next->fresh()->status)->toBe(MatchStatus::Staged);
});

test('re-roll voids the staged match and stages another', function () {
    [$user, , $session] = liveBoard(8);
    $match = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('reroll', $match->id)
        ->assertHasNoErrors();

    expect($match->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedMatch($session)->id)->not->toBe($match->id);
});

test('voiding a staged match goes through a confirmation panel', function () {
    [$user, , $session] = liveBoard(4);
    $match = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'void', $match->id)
        ->assertSeeHtml('data-test="confirm-void"')
        ->call('voidMatch')
        ->assertHasNoErrors()
        ->assertSet('panel', null);

    expect($match->fresh()->status)->toBe(MatchStatus::Void);
});

test('swap a staged player for a waiting player', function () {
    [$user, , $session, $players] = liveBoard(5);
    $match = stagedMatch($session);
    $out = $match->matchPlayers()->firstOrFail()->player_id;
    $in = SessionPlayer::query()->where('play_session_id', $session->id)->whereNotIn('player_id', $match->matchPlayers()->pluck('player_id'))->firstOrFail()->player_id;

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->call('swap')
        ->assertHasNoErrors();

    $ids = $match->matchPlayers()->pluck('player_id')->all();
    expect($ids)->toContain($in)->not->toContain($out);
});

test('swap without choosing players shows an inline error', function () {
    [$user, , $session] = liveBoard(5);
    $match = stagedMatch($session);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->call('swap')
        ->assertHasErrors('player');
});

test('remove fills the slot from the waiting list', function () {
    [$user, , $session] = liveBoard(5);
    $match = stagedMatch($session);
    $out = $match->matchPlayers()->firstOrFail()->player_id;

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'remove', $match->id)
        ->set('outPlayerId', (string) $out)
        ->set('removeStatus', 'break')
        ->call('remove')
        ->assertHasNoErrors();

    expect(SessionPlayer::where('player_id', $out)->value('status'))->toBe(SessionPlayerStatus::Break)
        ->and($match->matchPlayers()->pluck('player_id')->all())->not->toContain($out);
});

test('remove with nobody waiting shows an inline error and changes nothing', function () {
    [$user, , $session] = liveBoard(4);
    $match = stagedMatch($session);
    $out = $match->matchPlayers()->firstOrFail()->player_id;

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'remove', $match->id)
        ->set('outPlayerId', (string) $out)
        ->call('remove')
        ->assertHasErrors()
        ->assertSeeHtml('data-test="board-error"');

    expect($match->matchPlayers()->pluck('player_id')->all())->toContain($out);
});

test('auto-fill and the Up Next count are saved through the service', function () {
    [$user, , $session] = liveBoard(12);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->set('autoFill', true)
        ->assertHasNoErrors()
        ->set('upNextCount', '2')
        ->assertHasNoErrors();

    $session->refresh();
    expect($session->auto_fill)->toBeTrue()
        ->and($session->up_next_count)->toBe(2)
        ->and(GameMatch::where('play_session_id', $session->id)->where('status', MatchStatus::Staged->value)->count())->toBeLessThanOrEqual(2);
});

test('an invalid Up Next count is rejected and reset', function () {
    [$user, , $session] = liveBoard(4);

    Livewire::actingAs($user)->test(UpNext::class, ['session' => $session])
        ->set('upNextCount', '9')
        ->assertHasErrors()
        ->assertSet('upNextCount', '1');

    expect($session->fresh()->up_next_count)->toBe(1);
});

test('stale and foreign match ids give an inline error, not a 404', function () {
    [$user, , $session] = liveBoard(4);
    $foreign = GameMatch::factory()->create();

    $component = Livewire::actingAs($user)->test(UpNext::class, ['session' => $session]);
    $component->call('start', 999999)->assertHasErrors('match');
    $component->call('start', $foreign->id)->assertHasErrors('match');

    expect($foreign->fresh()->status)->toBe(MatchStatus::Staged);
});

// --- Courts ---

test('courts show free and playing courts with the teams', function () {
    [$user, , $session, $players] = liveBoard(4, ['courts' => 2]);
    startFirst($session);

    Livewire::actingAs($user)->test(Courts::class, ['session' => $session])
        ->assertSee('Court 1')
        ->assertSee('Court 2')
        ->assertSee('Free')
        ->assertSee($players[0]->name);
});

test('finishing a match records the score and frees the court', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);

    Livewire::actingAs($user)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'score', $match->id)
        ->set('scoreA', '11')
        ->set('scoreB', '7')
        ->call('finish')
        ->assertHasNoErrors()
        ->assertSet('panel', null);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Done)
        ->and([$match->team_a_score, $match->team_b_score])->toBe([11, 7])
        ->and(SessionPlayer::where('play_session_id', $session->id)->where('games_played', 1)->count())->toBe(4);
});

test('an invalid score is shown inline and nothing changes', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);

    $component = Livewire::actingAs($user)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'score', $match->id)
        ->set('scoreA', '11')
        ->set('scoreB', '10')
        ->assertSee('must lead by at least 2')
        ->call('finish')
        ->assertHasErrors('score')
        ->assertSeeHtml('data-test="board-error"');

    $component->set('scoreA', '')->call('finish')->assertHasErrors('scoreA');
    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('swap, remove and void a playing match', function () {
    [$user, , $session] = liveBoard(5);
    $match = startFirst($session);
    $ids = $match->matchPlayers()->pluck('player_id')->all();
    $spare = SessionPlayer::query()->where('play_session_id', $session->id)->whereNotIn('player_id', $ids)->firstOrFail()->player_id;

    $component = Livewire::actingAs($user)->test(Courts::class, ['session' => $session]);

    $component->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', (string) $ids[0])
        ->set('inPlayerId', (string) $spare)
        ->call('swap')
        ->assertHasNoErrors();
    expect($match->matchPlayers()->pluck('player_id')->all())->toContain($spare);

    $component->call('openPanel', 'void', $match->id)->call('voidMatch')->assertHasNoErrors();
    expect($match->fresh()->status)->toBe(MatchStatus::Void);
});

test('removing a player from a playing match with nobody waiting is an inline error', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);
    $out = $match->matchPlayers()->firstOrFail()->player_id;

    Livewire::actingAs($user)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'remove', $match->id)
        ->set('outPlayerId', (string) $out)
        ->call('remove')
        ->assertHasErrors();

    expect($match->matchPlayers()->pluck('player_id')->all())->toContain($out);
});

// --- Results ---

test('undo last reverts the most recent result and only it offers the button', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);
    app(MatchService::class)->finish($session->fresh(), $match, 11, 5);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSee('11 - 5')
        ->assertSeeHtml('data-test="undo-button"')
        ->call('undoLast')
        ->assertHasNoErrors();

    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('edit score updates a finished match and shows validation errors', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);
    app(MatchService::class)->finish($session->fresh(), $match, 11, 5);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->call('edit', $match->id)
        ->assertSet('scoreA', '11')
        ->set('scoreA', '11')
        ->set('scoreB', '10')
        ->call('saveScore')
        ->assertHasErrors('score')
        ->set('scoreA', '9')
        ->set('scoreB', '11')
        ->call('saveScore')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect([$match->fresh()->team_a_score, $match->fresh()->team_b_score])->toBe([9, 11]);
});

test('undo with nothing to undo shows an inline error', function () {
    [$user, , $session] = liveBoard(4);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->call('undoLast')
        ->assertHasErrors();
});

// --- waiting list and SessionBoard ---

test('the waiting list is ordered by games, then wait, then id, and excludes staged players', function () {
    [$user, , $session, $players] = liveBoard(9);
    // 4 are staged; 5 wait. Give the first waiting player a game and an earlier queue time to test the ordering.
    $staged = stagedMatch($session)->matchPlayers()->pluck('player_id')->all();
    $waiting = collect($players)->reject(fn (Player $p) => in_array($p->id, $staged, true))->values();

    $first = SessionPlayer::where('player_id', $waiting[0]->id)->firstOrFail();
    $first->games_played = 2;
    $first->save();
    $last = SessionPlayer::where('player_id', $waiting[4]->id)->firstOrFail();
    $last->queued_at = now()->subHour();
    $last->save();

    $rows = app(SessionBoard::class)->waiting($session);

    expect(array_column($rows, 'id'))->toBe([
        $waiting[4]->id, $waiting[1]->id, $waiting[2]->id, $waiting[3]->id, $waiting[0]->id,
    ]);

    Livewire::actingAs($user)->test(WaitingList::class, ['session' => $session])
        ->assertSeeInOrder([$waiting[4]->name, $waiting[1]->name, $waiting[0]->name]);
});

test('wait estimates use courts, staged matches and playing elapsed time', function () {
    // 1 court, 1 staged, 5 waiting; one match playing for 5 minutes (default average 15).
    [, , $session] = liveBoard(13, ['courts' => 1]);
    $match = startFirst($session);
    $match->forceFill(['started_at' => now()->subMinutes(5)])->save();

    $rows = app(SessionBoard::class)->waiting($session->fresh());

    // Court frees in 10 min, then the staged match runs 10 to 25, then the first unstaged group starts at 25.
    expect($rows[0]['estimate_minutes'])->toBe(25)
        ->and($rows[3]['estimate_minutes'])->toBe(25)
        ->and($rows[4]['estimate_minutes'])->toBe(40);
});

test('the average match duration uses the last 10 done matches, else the config default', function () {
    [, , $session] = liveBoard(4);
    $board = app(SessionBoard::class);

    expect($board->averageMatchMinutes($session))->toBe(15.0);

    $match = startFirst($session);
    $match->forceFill(['started_at' => now()->subMinutes(20)])->save();
    app(MatchService::class)->finish($session->fresh(), $match->fresh(), 11, 3);

    expect(round($board->averageMatchMinutes($session), 1))->toBe(20.0);
});

test('players on break are listed separately and the board never changes state', function () {
    [$user, , $session, $players] = liveBoard(6);
    app(CheckInService::class)->goOnBreak($session, $players[5]);

    $before = [GameMatch::count(), SessionPlayer::count()];
    $board = app(SessionBoard::class);

    expect(array_column($board->onBreak($session), 'id'))->toBe([$players[5]->id]);
    $board->courts($session);
    $board->waiting($session);
    $board->recent($session);
    expect([GameMatch::count(), SessionPlayer::count()])->toBe($before);

    Livewire::actingAs($user)->test(WaitingList::class, ['session' => $session])
        ->assertSeeHtml('data-test="break-list"')
        ->assertSee($players[5]->name);
});

test('two devices changing different Up Next settings both survive', function () {
    [$user, , $session] = liveBoard(12);

    $a = Livewire::actingAs($user)->test(UpNext::class, ['session' => $session]);
    $b = Livewire::actingAs($user)->test(UpNext::class, ['session' => $session]);

    $a->set('autoFill', true)->assertHasNoErrors();
    $b->set('upNextCount', '2')->assertHasNoErrors();

    $session->refresh();
    expect($session->auto_fill)->toBeTrue()
        ->and($session->up_next_count)->toBe(2);

    // B's stale auto-fill value is refreshed on its next request.
    $b->call('$refresh')->assertSet('autoFill', true)->assertSet('upNextCount', '2');
});

test('a finished match can be voided from the results after confirming', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);
    app(MatchService::class)->finish($session->fresh(), $match, 11, 5);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->call('confirmVoid', $match->id)
        ->assertSeeHtml('data-test="confirm-void-result"')
        ->assertSee('removed from the players')
        ->call('voidMatch')
        ->assertHasNoErrors()
        ->assertSet('voidingId', null);

    expect($match->fresh()->status)->toBe(MatchStatus::Void)
        ->and(SessionPlayer::where('play_session_id', $session->id)->sum('games_played'))->toBe(0);
});

test('the void button is hidden for a DUPR-exported match', function () {
    [$user, , $session] = liveBoard(4);
    $match = startFirst($session);
    app(MatchService::class)->finish($session->fresh(), $match, 11, 5);
    $match->forceFill(['dupr_exported_at' => now()])->save();

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSeeHtml('data-test="edit-score-button"')
        ->assertDontSeeHtml('data-test="void-result-button"')
        ->call('confirmVoid', $match->id)
        ->call('voidMatch')
        ->assertHasErrors();

    expect($match->fresh()->status)->toBe(MatchStatus::Done);
});

test('only the last 10 done matches count towards the average duration', function () {
    [, , $session] = liveBoard(0);

    foreach (range(1, 12) as $i) {
        $finished = now()->subMinutes(100 - $i);
        GameMatch::factory()->create([
            'play_session_id' => $session->id,
            'status' => MatchStatus::Done,
            'court_no' => 1,
            'team_a_score' => 11,
            'team_b_score' => 5,
            // The 2 oldest take 60 minutes, the newest 10 take 10 minutes.
            'started_at' => $finished->copy()->subMinutes($i <= 2 ? 60 : 10),
            'finished_at' => $finished,
        ]);
    }

    expect(round(app(SessionBoard::class)->averageMatchMinutes($session), 2))->toBe(10.0);
});

test('a board poll runs a small fixed number of queries', function () {
    [$user, , $session] = liveBoard(12, ['courts' => 4]);
    startFirst($session);
    $component = Livewire::actingAs($user)->test(UpNext::class, ['session' => $session]);

    DB::enableQueryLog();
    $component->call('$refresh');
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThan(25);
});
