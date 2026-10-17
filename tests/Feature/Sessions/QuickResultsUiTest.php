<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\Results;
use App\Livewire\Sessions\UpNext;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\MatchService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * A live session in a club with an owner and $n checked-in players (Up Next staging runs).
 *
 * @param  array<string, mixed>  $attrs
 * @return array{0: User, 1: Club, 2: PlaySession}
 */
function qresBoard(int $n, array $attrs = []): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->live()->create($attrs);
    for ($i = 0; $i < $n; $i++) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($club)->manual(3)->create());
        test()->travel(1)->seconds();
    }

    return [$owner, $club, $session->fresh()];
}

function qresStaged(PlaySession $session, int $index = 0): GameMatch
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Staged->value)->orderBy('id')->get()->values()->get($index) ?? throw new RuntimeException('No staged match');
}

function qresStart(PlaySession $session): GameMatch
{
    $match = qresStaged($session);
    app(MatchService::class)->startMatch($session->fresh(), $match);

    return $match->fresh();
}

/** @return list<int> */
function qresPlayerIds(GameMatch $match): array
{
    return $match->matchPlayers()->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
}

// --- Team A wins / Team B wins on the court card ---

test('the court card has two big win buttons and no Enter score button', function () {
    [$owner, , $session] = qresBoard(4);
    qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertSeeHtml('data-test="win-a"')
        ->assertSeeHtml('data-test="win-b"')
        ->assertSee('Team A wins')
        ->assertSee('Team B wins')
        ->assertSeeHtml('data-test="swap-button"')
        ->assertSeeHtml('data-test="void-button"')
        ->assertDontSeeHtml('data-test="score-button"')
        ->assertDontSee('Enter score');
});

test('when no score is required a win tap records the result at once', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'B', $match->id)
        ->assertHasNoErrors()
        ->assertSet('panel', null)
        ->assertDontSeeHtml('data-test="score-form"');

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Done)
        ->and($match->winner)->toBe(Team::B)
        ->and([$match->team_a_score, $match->team_b_score])->toBe([null, null])
        ->and(SessionPlayer::where('play_session_id', $session->id)->where('games_played', 1)->count())->toBe(4);
});

test('when a score is required a win tap opens the form with the winning score prefilled', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => true]);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->assertSet('panel', 'score')
        ->assertSet('winner', 'A')
        ->assertSet('scoreA', '11')
        ->assertSet('scoreB', '')
        ->assertSeeHtml('data-test="score-form"')
        ->assertSee('Team A wins')
        ->call('declareWinner', 'B', $match->id)
        ->assertSet('winner', 'B')
        ->assertSet('scoreA', '')
        ->assertSet('scoreB', '11');

    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('the prefill follows the session scoring target', function () {
    [$owner, , $session] = qresBoard(4, ['scoring' => ['type' => 'side_out', 'games' => 1, 'to' => 15, 'win_by' => 2]]);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'B', $match->id)
        ->assertSet('scoreB', '15');
});

test('submitting 12 to 10 for the tapped winner works', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->set('scoreA', '12')
        ->set('scoreB', '10')
        ->assertDontSeeHtml('data-test="score-hint"')
        ->call('finish')
        ->assertHasNoErrors()
        ->assertSet('panel', null);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Done)
        ->and($match->winner)->toBe(Team::A)
        ->and([$match->team_a_score, $match->team_b_score])->toBe([12, 10]);
});

test('a score that disagrees with the tapped winner shows the hint and is refused', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->set('scoreA', '5')
        ->set('scoreB', '11')
        ->assertSeeHtml('data-test="score-hint"')
        ->assertSee("Team A's score must be higher.")
        ->call('finish')
        ->assertHasErrors('score')
        ->assertSet('panel', 'score');

    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('an invalid score shows the score rule as a live hint', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->set('scoreB', '10')
        ->assertSee('must lead by at least 2');
});

test('cancel closes the score form without recording anything', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->call('closePanel')
        ->assertSet('panel', null)
        ->assertSet('winner', '');

    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('a bad team value is refused and a stale match shows an inline error', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);
    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);

    $component->call('declareWinner', 'C', $match->id)->assertHasErrors('score');
    $component->call('declareWinner', 'A', 999999)->assertHasErrors('match');
    expect($match->fresh()->status)->toBe(MatchStatus::Playing);

    $component->call('declareWinner', 'A', $match->id)->assertHasNoErrors();
    $component->call('declareWinner', 'A', $match->id)->assertHasErrors();
});

test('users outside the club are refused on the new court actions', function () {
    [, , $session] = qresBoard(4);
    $match = qresStart($session);
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)->test(Courts::class, ['session' => $session])->assertNotFound();

    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

// --- Edit result ---

test('Edit result can switch the winner', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::A, 11, 5);

    Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->assertSee('Edit result')
        ->assertDontSee('Edit score')
        ->call('edit', $match->id)
        ->assertSet('editWinner', 'A')
        ->assertSeeHtml('data-test="edit-result-form"')
        ->set('editWinner', 'B')
        ->set('scoreA', '8')
        ->set('scoreB', '11')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    $match->refresh();
    expect($match->winner)->toBe(Team::B)
        ->and([$match->team_a_score, $match->team_b_score])->toBe([8, 11]);
});

test('Edit result can add a score to a winner-only match', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::B);

    Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->call('edit', $match->id)
        ->assertSet('editWinner', 'B')
        ->assertSet('scoreA', '')
        ->assertSee('Leave both scores blank')
        ->set('scoreA', '9')
        ->set('scoreB', '11')
        ->call('saveResult')
        ->assertHasNoErrors();

    $match->refresh();
    expect($match->winner)->toBe(Team::B)
        ->and([$match->team_a_score, $match->team_b_score])->toBe([9, 11]);
});

test('Edit result scores are optional when the session does not require them, required when it does', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::A);

    $component = Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->call('edit', $match->id)
        ->set('editWinner', 'B')
        ->call('saveResult')
        ->assertHasNoErrors();
    expect($match->fresh()->winner)->toBe(Team::B);

    $session->update(['require_score' => true]);
    $component->call('edit', $match->id)
        ->assertSee('requires a score')
        ->call('saveResult')
        ->assertHasErrors('score');

    $component->set('scoreA', '11')->call('saveResult')->assertHasErrors('score'); // both or neither
    $component->set('editWinner', 'A')->set('scoreA', '4')->set('scoreB', '11')->call('saveResult')->assertHasErrors('score'); // disagrees
    expect($match->fresh()->winner)->toBe(Team::B);
});

test('a bad winner value is refused when editing', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::A, 11, 5);

    Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->call('edit', $match->id)
        ->set('editWinner', 'Z')
        ->call('saveResult')
        ->assertHasErrors('score');
});

test('users outside the club are refused on Edit result', function () {
    [, , $session] = qresBoard(4);

    Livewire::actingAs(User::factory()->create())->test(Results::class, ['session' => $session])->assertNotFound();
});

// --- Winner-only display ---

test('a winner-only match shows Won and no score pill in Match history', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::A);

    Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->assertSeeHtml('data-test="result-winner"')
        ->assertSee('Won')
        ->assertDontSeeHtml('data-test="result-score"')
        ->assertDontSee('0-0')
        ->assertDontSee('– - –');
});

test('a scored match still shows its score in Match history', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);
    app(MatchService::class)->finish($session->fresh(), $match, Team::A, 11, 5);

    Livewire::actingAs($owner)->test(Results::class, ['session' => $session])
        ->assertSeeHtml('data-test="result-score"')
        ->assertSeeInOrder(['11', '-', '5']);
});

test('the staff results page and the public ended page show Won without a score pill', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create(['leaderboard_min_games' => 1]);
    $session = PlaySession::factory()->for($club)->ended()->create(['require_score' => false]);
    $players = Player::factory()->for($club)->count(4)->create();
    $match = GameMatch::factory()->create([
        'play_session_id' => $session->id, 'status' => MatchStatus::Done, 'court_no' => 1,
        'team_a_score' => null, 'team_b_score' => null, 'winner' => Team::A,
        'started_at' => now()->subMinutes(15), 'finished_at' => now(),
    ]);
    foreach ($players as $i => $player) {
        $match->matchPlayers()->create(['player_id' => $player->id, 'team' => $i < 2 ? 'A' : 'B', 'slot' => ($i % 2) + 1]);
    }

    $this->actingAs($owner)->get(route('clubs.sessions.results', [$club, $session]))
        ->assertOk()
        ->assertSeeHtml('data-test="won-marker"')
        ->assertDontSeeHtml('data-test="score-pill"')
        ->assertDontSee('– - –');

    auth()->logout();
    $this->get(route('public.queue', [$club, $session->public_id]))
        ->assertOk()
        ->assertSeeHtml('data-test="won-marker"')
        ->assertDontSeeHtml('data-test="score-pill"')
        ->assertDontSee('– - –');
});

// --- Require score checkboxes ---

test('the club settings checkbox saves require score', function () {
    [$owner, $club] = memberOf();

    $component = Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->assertSeeHtml('data-test="require-score"')
        ->assertSee('Require a score for every match')
        ->assertSee('not sent to DUPR')
        ->assertSet('require_score', true)
        ->set('require_score', false)
        ->call('saveSessionSettings')
        ->assertHasNoErrors();

    expect($club->fresh()->require_score)->toBeFalse();

    $component->set('require_score', true)->call('saveSessionSettings');
    expect($club->fresh()->require_score)->toBeTrue();
});

test('a new session starts from the club value and the form saves the checkbox', function () {
    [$owner, $club] = memberOf();
    $club->update(['require_score' => false]);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club->fresh()])
        ->assertSeeHtml('data-test="require-score"')
        ->assertSee('not sent to DUPR')
        ->assertSet('require_score', false)
        ->set('require_score', true)
        ->call('save')
        ->assertHasNoErrors();

    $session = $club->playSessions()->firstOrFail();
    expect($session->require_score)->toBeTrue();

    $club->update(['require_score' => true]);
    Livewire::actingAs($owner)->test(Form::class, ['club' => $club->fresh()])
        ->assertSet('require_score', true)
        ->set('require_score', false)
        ->call('save');
    expect($club->playSessions()->orderByDesc('id')->firstOrFail()->require_score)->toBeFalse();
});

test('the session form edits require score on a live session and disables it once ended', function () {
    [$owner, $club, $session] = qresBoard(0);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSet('require_score', true)
        ->set('require_score', false)
        ->call('save')
        ->assertHasNoErrors();
    expect($session->fresh()->require_score)->toBeFalse();

    $ended = PlaySession::factory()->for($club)->ended()->create();
    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $ended])
        ->assertSeeHtml('data-test="require-score"')
        ->assertSeeHtml('disabled');
});

// --- Swap panel ---

test('the swap panel lists waiting players first, then Up Next players with their number', function () {
    [$owner, , $session] = qresBoard(10, ['up_next_count' => 2]);
    $first = qresStaged($session, 0);
    $second = qresStaged($session, 1);

    $html = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $first->id)
        ->assertSeeHtml('data-test="in-group" data-group="waiting"')
        ->assertSeeHtml('data-test="in-group" data-group="up_next"')
        ->assertSeeHtml('data-source="up_next"')
        ->assertSee('Up Next 2')
        ->html();

    expect(strpos($html, 'data-group="waiting"'))->toBeLessThan(strpos($html, 'data-group="up_next"'));
    foreach (qresPlayerIds($second) as $id) {
        expect($html)->toContain('data-test="in-option" data-player="'.$id.'"');
    }
    foreach (qresPlayerIds($first) as $id) {
        expect($html)->not->toContain('data-test="in-option" data-player="'.$id.'"');
    }
});

test('picks survive a refresh and a board-changed event', function () {
    [$owner, , $session] = qresBoard(6);
    $match = qresStaged($session);
    $out = qresPlayerIds($match)[0];
    $in = SessionPlayer::query()->where('play_session_id', $session->id)->whereNotIn('player_id', qresPlayerIds($match))->firstOrFail()->player_id;

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->call('$refresh')
        ->assertSet('outPlayerId', (string) $out)
        ->assertSet('inPlayerId', (string) $in)
        ->dispatch('board-changed')
        ->assertSet('outPlayerId', (string) $out)
        ->assertSet('inPlayerId', (string) $in)
        ->assertSet('pickNotice', '')
        ->assertSeeHtml('data-selected="1"')
        ->call('swap')
        ->assertHasNoErrors();

    expect(qresPlayerIds($match->fresh()))->toContain($in)->not->toContain($out);
});

test('the lists pick up players who check in while the panel is open', function () {
    [$owner, $club, $session] = qresBoard(5);
    $match = qresStaged($session);

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id);
    $newcomer = Player::factory()->for($club)->manual(3)->create(['name' => 'Latecomer Lou']);
    expect($component->html())->not->toContain('Latecomer Lou');

    app(CheckInService::class)->checkIn($session, $newcomer);
    $component->call('$refresh')->assertSee('Latecomer Lou');
});

test('a stale pick is cleared with a message instead of an error', function () {
    [$owner, , $session] = qresBoard(6);
    $match = qresStaged($session);
    $out = qresPlayerIds($match)[0];
    $in = SessionPlayer::query()->where('play_session_id', $session->id)->whereNotIn('player_id', qresPlayerIds($match))->firstOrFail();

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in->player_id);

    // Another device sends the picked player on break.
    $in->forceFill(['status' => SessionPlayerStatus::Break])->save();

    $component->call('$refresh')
        ->assertHasNoErrors()
        ->assertSet('inPlayerId', '')
        ->assertSet('outPlayerId', (string) $out)
        ->assertSeeHtml('data-test="swap-pick-notice"')
        ->assertSee('no longer available to swap in');

    // Picking again clears the message.
    $other = SessionPlayer::query()->where('play_session_id', $session->id)->where('status', SessionPlayerStatus::Waiting->value)->whereNotIn('player_id', qresPlayerIds($match))->firstOrFail();
    $component->set('inPlayerId', (string) $other->player_id)->assertSet('pickNotice', '');
});

test('a swapped-out pick that left the match is cleared too', function () {
    [$owner, , $session] = qresBoard(6, ['courts' => 2]);
    $match = qresStaged($session);
    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', '999999');

    $component->call('$refresh')->assertSet('outPlayerId', '')->assertSee('no longer in this match');
});

test('the panel closes with a message when its match is gone', function () {
    [$owner, , $session] = qresBoard(6);
    $match = qresStaged($session);
    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id);

    app(MatchService::class)->void($session->fresh(), $match);

    $component->call('$refresh')->assertSet('panel', null)->assertHasErrors('match');
});

test('swapping in an Up Next player works from the Up Next component', function () {
    [$owner, , $session] = qresBoard(9, ['up_next_count' => 2]);
    $first = qresStaged($session, 0);
    $second = qresStaged($session, 1);
    $out = qresPlayerIds($first)[0];
    $in = qresPlayerIds($second)[0];

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $first->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->call('swap')
        ->assertHasNoErrors()
        ->assertSet('panel', null);

    expect(qresPlayerIds($first->fresh()))->toContain($in)->not->toContain($out);
    foreach ([$first, $second] as $staged) {
        $fresh = $staged->fresh();
        if ($fresh->status === MatchStatus::Staged) {
            expect(count(qresPlayerIds($fresh)))->toBe(4);
        }
    }
});

test('a court card swap lists the Up Next players and swaps them in', function () {
    [$owner, , $session] = qresBoard(8, ['up_next_count' => 1]);
    $playing = qresStart($session);
    $staged = qresStaged($session);
    $out = qresPlayerIds($playing)[0];
    $in = qresPlayerIds($staged)[0];

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'swap', $playing->id)
        ->assertSeeHtml('data-source="up_next"')
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->call('swap')
        ->assertHasNoErrors();

    expect(qresPlayerIds($playing->fresh()))->toContain($in)->not->toContain($out);
});

test('the swap panel shows an empty state when nobody can be swapped in', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->assertSeeHtml('data-test="swap-empty"')
        ->assertSee('Nobody is waiting. Void the match instead.')
        ->call('openPanel', 'void', $match->id)
        ->assertSeeHtml('data-test="confirm-void"');
});

test('the mixed doubles gender hint stays in the swap panel', function () {
    [$owner, , $session] = qresBoard(0, ['rotation_mode' => 'mixed']);
    $match = GameMatch::factory()->create(['play_session_id' => $session->id, 'status' => MatchStatus::Staged]);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->assertSeeHtml('data-test="swap-gender-hint"');
});

test('users outside the club are refused on the swap and void actions', function () {
    [, , $session] = qresBoard(6);
    $match = qresStaged($session);

    Livewire::actingAs(User::factory()->create())->test(UpNext::class, ['session' => $session])->assertNotFound();
    expect($match->fresh()->status)->toBe(MatchStatus::Staged);
});

// --- Remove is gone ---

test('no Remove button, panel or text remains on the board cards', function () {
    [$owner, , $session] = qresBoard(8, ['up_next_count' => 1]);
    $playing = qresStart($session);
    $staged = qresStaged($session);

    $courts = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'swap', $playing->id)
        ->assertDontSeeHtml('remove-button')
        ->assertDontSeeHtml('confirm-remove')
        ->assertDontSeeHtml('remove-status')
        ->call('openPanel', 'remove', $playing->id)
        ->assertSet('panel', null);
    $upNext = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $staged->id)
        ->assertDontSeeHtml('remove-button')
        ->assertDontSeeHtml('confirm-remove')
        ->assertDontSee('Remove player');

    expect(property_exists(Courts::class, 'removeStatus'))->toBeFalse()
        ->and(method_exists(UpNext::class, 'remove'))->toBeFalse()
        ->and($courts->html())->not->toContain('Remove')
        ->and($upNext->html())->not->toContain('Remove');
});

// --- Phase 18 review fixes ---

test('a pick from Waiting that a refill staged meanwhile is cleared and nothing changes', function () {
    [$owner, , $session] = qresBoard(5, ['up_next_count' => 2]);
    $first = qresStaged($session, 0);
    $out = qresPlayerIds($first)[0];
    $in = SessionPlayer::query()->where('play_session_id', $session->id)->where('status', SessionPlayerStatus::Waiting->value)->whereNotIn('player_id', qresPlayerIds($first))->firstOrFail()->player_id;

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $first->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->assertSet('inMatchId', null);

    // Three more players check in, so the refill stages Up Next 2 with the picked player in it.
    foreach (range(1, 3) as $i) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($session->club)->manual(3)->create());
    }
    $second = qresStaged($session, 1);
    expect(qresPlayerIds($second))->toContain($in);
    $before = [qresPlayerIds($first->fresh()), qresPlayerIds($second->fresh())];

    $component->call('$refresh')
        ->assertHasNoErrors()
        ->assertSet('inPlayerId', '')
        ->assertSee('has moved');
    $component->call('swap')->assertHasErrors('player');

    expect([qresPlayerIds($first->fresh()), qresPlayerIds($second->fresh())])->toBe($before);
});

test('the service refuses a stale source when the component swaps, inline and not as a 500', function () {
    [$owner, , $session] = qresBoard(9, ['up_next_count' => 2]);
    $first = qresStaged($session, 0);
    $second = qresStaged($session, 1);
    $out = qresPlayerIds($first)[0];
    $in = qresPlayerIds($second)[0];

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $first->id)
        ->set('outPlayerId', (string) $out)
        ->set('inPlayerId', (string) $in)
        ->assertSet('inMatchId', $second->id);

    // The picked player's match is voided by someone else before the tap: they are waiting now.
    app(MatchService::class)->void($session->fresh(), $second);
    $before = qresPlayerIds($first->fresh());

    $component->call('swap')->assertHasErrors('player')->assertSet('inPlayerId', '');
    expect(qresPlayerIds($first->fresh()))->toBe($before);
});

test('the Up Next panel closes with a message when its match starts', function () {
    [$owner, , $session] = qresBoard(8, ['courts' => 2]);
    $match = qresStaged($session);
    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->assertSet('panelMatchStatus', 'staged');

    app(MatchService::class)->startMatch($session->fresh(), $match);

    $component->call('$refresh')->assertSet('panel', null)->assertHasErrors('match');
});

test('the court panel closes when its match is no longer playing', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);
    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id);

    app(MatchService::class)->finish($session->fresh(), $match, Team::A, 11, 3);

    $component->call('$refresh')->assertSet('panel', null)->assertHasErrors('match');
});

test('when another device turns require score on, a winner-only tap does not record or 500', function () {
    [$owner, , $session] = qresBoard(4, ['require_score' => false]);
    $match = qresStart($session);
    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);

    $session->forceFill(['require_score' => true])->save();

    $component->call('declareWinner', 'A', $match->id);
    expect($match->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($match->fresh()->winner)->toBeNull();
    // Either the score form opened (the session was re-read) or the service error shows inline.
    expect($component->get('panel') === 'score' || $component->errors()->isNotEmpty())->toBeTrue();
});

test('a client cannot set the winner of the score form', function () {
    [$owner, , $session] = qresBoard(4);
    $match = qresStart($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('declareWinner', 'A', $match->id)
        ->set('winner', 'B');
})->throws(CannotUpdateLockedPropertyException::class);
