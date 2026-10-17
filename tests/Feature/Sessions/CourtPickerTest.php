<?php

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\SkillGroups;
use App\Domain\Rotation\Weights;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\UpNext;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\Rotation\BalancedStrategy;
use App\Services\SessionBoard;
use Livewire\Livewire;

/** @return list<GameMatch> */
function cpStaged(PlaySession $session): array
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Staged->value)->orderBy('id')->get()->all();
}

function cpOwner(PlaySession $session): User
{
    $owner = User::factory()->create();
    $session->club->users()->attach($owner, ['role' => 'owner']);

    return $owner;
}

/** A live skill courts session (2 groups over 4 courts: courts 1-2 and 3-4), $stars checked in. */
function cpSkillSession(array $stars, int $upNext = 1): PlaySession
{
    $session = PlaySession::factory()->for(Club::factory()->create())->live()->create([
        'rotation_mode' => RotationMode::SkillCourts,
        'courts' => 4,
        'up_next_count' => $upNext,
        'mode_settings' => ['winners_stay_max_wins' => 2, 'skill_groups' => SkillGroups::defaultFor(4)->toArray()],
    ]);
    foreach ($stars as $s) {
        $player = Player::factory()->for($session->club)->manual($s)->create();
        app(CheckInService::class)->checkIn($session, $player);
        test()->travel(1)->seconds();
    }

    return $session->fresh();
}

/** Strategy that binds each staged match to the court stored on it, like winners stay and King of the Court. */
function cpBindCourtBound(): void
{
    app()->bind('rotation.strategy.balanced', fn () => new class(new BalancedRotationEngine(Weights::fromConfig((array) config('pickleq.rotation')))) extends BalancedStrategy
    {
        public function allowedCourts(PlaySession $session, GameMatch $match): array
        {
            return $match->court_no === null ? [] : [$match->court_no];
        }
    });
}

// --- P17.1 board data ---

test('balanced: staged rows are numbered in order and offer every free court', function () {
    [$session] = board(12, ['courts' => 3, 'up_next_count' => 2]);
    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 2);
    $session = $session->fresh();
    // Starting restaged one more match: two staged rows again.
    $rows = app(SessionBoard::class)->staged($session);

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'number'))->toBe([1, 2])
        ->and($rows[0]['free_courts'])->toBe([1, 3])
        ->and($rows[1]['free_courts'])->toBe([1, 3]);
});

test('balanced: free courts carry the staged matches that may start there and busy courts none', function () {
    [$session] = board(12, ['courts' => 3, 'up_next_count' => 2]);
    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 2);
    $session = $session->fresh();
    $staged = cpStaged($session);

    $courts = app(SessionBoard::class)->courts($session);

    expect($courts[0]['up_next'])->toHaveCount(2)
        ->and(array_column($courts[0]['up_next'], 'id'))->toBe([$staged[0]->id, $staged[1]->id])
        ->and($courts[1]['match'])->not->toBeNull()
        ->and($courts[1]['up_next'])->toBe([])
        ->and(array_column($courts[2]['up_next'], 'number'))->toBe([1, 2]);
});

test('with no free court, staged rows offer none', function () {
    [$session] = board(8, ['courts' => 1, 'up_next_count' => 1]);
    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 1);
    $session = $session->fresh();

    $rows = app(SessionBoard::class)->staged($session);

    expect($rows[0]['free_courts'])->toBe([])
        ->and(app(SessionBoard::class)->courts($session)[0]['up_next'])->toBe([]);
});

test('the up next and court lookups can be skipped on request', function () {
    [$session] = board(8, ['courts' => 3, 'up_next_count' => 2]);
    $board = app(SessionBoard::class);

    // Free courts and staged matches exist, so the default carries them.
    expect($board->courts($session)[0]['up_next'])->not->toBe([])
        ->and($board->staged($session)[0]['free_courts'])->not->toBe([]);

    foreach ($board->courts($session, false) as $row) {
        expect($row['up_next'])->toBe([]);
    }
    foreach ($board->staged($session, false) as $row) {
        expect($row['free_courts'])->toBe([])->and($row['number'])->toBeInt();
    }
});

test('skill courts: numbers restart per group and a match only offers free courts of its group', function () {
    $session = cpSkillSession([5, 5, 5, 5, 5, 5, 5, 5, 2, 2, 2, 2], upNext: 2);
    $rows = app(SessionBoard::class)->staged($session);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'group'))->toBe([1, 1, 2])
        ->and(array_column($rows, 'number'))->toBe([1, 2, 1])
        ->and($rows[0]['free_courts'])->toBe([1, 2])
        ->and($rows[2]['free_courts'])->toBe([3, 4]);

    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 1);
    $session = $session->fresh();
    $rows = app(SessionBoard::class)->staged($session);

    expect($rows[0]['free_courts'])->toBe([2])
        ->and(end($rows)['free_courts'])->toBe([3, 4]);

    $courts = app(SessionBoard::class)->courts($session);
    expect($courts[0]['up_next'])->toBe([])
        ->and(array_column($courts[1]['up_next'], 'group'))->toBe([1])
        ->and(array_column($courts[2]['up_next'], 'group'))->toBe([2])
        ->and(array_column($courts[3]['up_next'], 'group'))->toBe([2]);
});

test('court-bound mode: a bound staged match only shows on its own court', function () {
    [$session] = board(8, ['courts' => 3, 'up_next_count' => 2]);
    [$first, $second] = cpStaged($session);
    $first->update(['court_no' => 2]);
    $second->update(['court_no' => 3]);
    cpBindCourtBound();

    $board = app(SessionBoard::class);
    $rows = $board->staged($session);
    $courts = $board->courts($session);

    expect($rows[0]['free_courts'])->toBe([2])
        ->and($rows[1]['free_courts'])->toBe([3])
        ->and($courts[0]['up_next'])->toBe([])
        ->and(array_column($courts[1]['up_next'], 'id'))->toBe([$first->id])
        ->and(array_column($courts[2]['up_next'], 'id'))->toBe([$second->id]);
});

test('building the board data does not query once per staged match', function () {
    $count = function (int $upNext): int {
        [$session] = board(12, ['courts' => 3, 'up_next_count' => $upNext]);
        expect(cpStaged($session))->toHaveCount($upNext);

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(SessionBoard::class)->courts($session->fresh());
        app(SessionBoard::class)->staged($session->fresh());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(3))->toBe($count(1));
});

// --- P17.2 Up Next card ---

test('the staged card is labelled Up Next N and sends the match to the chosen court', function () {
    [$session] = board(8, ['courts' => 3, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    [$first, $second] = cpStaged($session);

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->assertSee('Up Next 1')
        ->assertSee('Up Next 2')
        ->assertDontSee('Staged #')
        ->assertSee('Available courts');

    $component->call('start', $second->id, 3)->assertHasNoErrors();

    expect($second->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($second->fresh()->court_no)->toBe(3)
        ->and($first->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a court outside the skill group of the match is refused', function () {
    $session = cpSkillSession([5, 5, 5, 5]);
    $owner = cpOwner($session);
    [$top] = cpStaged($session);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('start', $top->id, 3)
        ->assertHasErrors('court')
        ->assertSee("That court is not in this match's skill group.");

    expect($top->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a stale tap on a court that was taken meanwhile shows the board error', function () {
    [$session] = board(8, ['courts' => 2, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    [$first, $second] = cpStaged($session);

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session]);
    app(MatchService::class)->startMatch($session->fresh(), $first, 2);

    $component->call('start', $second->id, 2)
        ->assertHasErrors('court')
        ->assertSeeHtml('data-test="board-error"')
        ->assertSee('That court is in use')
        ->assertDispatched('board-changed');

    expect($second->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a non-numeric court is rejected', function () {
    [$session] = board(4);
    $owner = cpOwner($session);
    [$match] = cpStaged($session);

    expect(fn () => Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])->call('start', $match->id, 'abc'))
        ->toThrow(TypeError::class);

    expect($match->fresh()->status)->toBe(MatchStatus::Staged);
});

// --- P17.3 free court card ---

test('a free court previews the first Up Next match and starts it with one tap', function () {
    [$session] = board(8, ['courts' => 2, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    [$first, $second] = cpStaged($session);

    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertSeeHtml('data-test="court-up-next"')
        ->assertSee('Start Up Next 1')
        ->assertSeeHtml('data-test="choose-players-button"');

    $component->call('startOnCourt', 2, $first->id)
        ->assertHasNoErrors()
        ->assertDispatched('board-changed');

    expect($first->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($first->fresh()->court_no)->toBe(2)
        ->and($second->fresh()->status)->toBe(MatchStatus::Staged);
});

test('the court start button is on every free court and names the first eligible match', function () {
    [$session] = board(8, ['courts' => 3, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 1);

    $html = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session->fresh()])->html();

    expect(substr_count($html, 'data-test="court-start-up-next"'))->toBe(2)
        ->and(substr_count($html, 'data-test="court-up-next"'))->toBe(2)
        ->and(substr_count($html, 'data-test="choose-players-button"'))->toBe(2);
});

test('the choose a matchup modal lists only the matches that may start on that court', function () {
    $session = cpSkillSession([5, 5, 5, 5, 5, 5, 5, 5, 2, 2, 2, 2], upNext: 2);
    $owner = cpOwner($session);
    $staged = cpStaged($session);

    $html = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])->html();

    // One modal per free court. Court 1 (group 1): the two top matches. Court 3 (group 2): the bottom match.
    $modals = preg_split('/data-test="choose-matchup-modal"/', $html);
    expect($modals)->toHaveCount(5)
        ->and(substr_count($modals[1], 'data-test="choose-matchup-option"'))->toBe(2)
        ->and(substr_count($modals[3], 'data-test="choose-matchup-option"'))->toBe(1)
        ->and($modals[3])->toContain('data-match="'.$staged[2]->id.'"')
        ->and($modals[3])->not->toContain('data-match="'.$staged[0]->id.'"')
        ->and($html)->toContain('Choose a matchup for Court 1')
        ->and($html)->toContain('Choose a matchup for Court 3')
        ->and($html)->toContain('Cancel')
        ->and($html)->not->toContain('Choose manually');
});

test('starting a match from the modal uses that court, and an ineligible match is refused', function () {
    $session = cpSkillSession([5, 5, 5, 5, 5, 5, 5, 5, 2, 2, 2, 2], upNext: 2);
    $owner = cpOwner($session);
    [$top1, $top2, $bottom] = cpStaged($session);

    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);

    $component->call('startOnCourt', 1, $bottom->id)
        ->assertHasErrors('court')
        ->assertSeeHtml('data-test="board-error"');
    expect($bottom->fresh()->status)->toBe(MatchStatus::Staged);

    $component->call('startOnCourt', 2, $top2->id)->assertHasNoErrors();

    expect($top2->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($top2->fresh()->court_no)->toBe(2)
        ->and($top1->fresh()->status)->toBe(MatchStatus::Staged);
});

test('court-bound mode: the modal and button on a court only carry its own bound match', function () {
    [$session] = board(8, ['courts' => 3, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    [$first, $second] = cpStaged($session);
    $first->update(['court_no' => 2]);
    $second->update(['court_no' => 3]);
    cpBindCourtBound();

    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);
    $html = $component->html();

    expect($html)->toContain('No one up next for this court')
        ->and(substr_count($html, 'data-test="court-start-up-next"'))->toBe(2);

    $component->call('startOnCourt', 2, $second->id)->assertHasErrors('court');
    $component->call('startOnCourt', 2, $first->id)->assertHasNoErrors();

    expect($first->fresh()->court_no)->toBe(2)->and($first->fresh()->status)->toBe(MatchStatus::Playing);
});

test('a free court with no Up Next match says so and has no buttons', function () {
    [$session] = board(0, ['courts' => 2]);
    $owner = cpOwner($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertSee('No one up next for this court')
        ->assertDontSeeHtml('data-test="court-start-up-next"')
        ->assertDontSeeHtml('data-test="choose-players-button"')
        ->assertDontSeeHtml('data-test="choose-matchup-modal"');
});

test('a court taken by a playing match shows no start buttons', function () {
    [$session] = board(8, ['courts' => 1, 'up_next_count' => 1]);
    $owner = cpOwner($session);
    app(MatchService::class)->startMatch($session, cpStaged($session)[0], 1);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session->fresh()])
        ->assertDontSeeHtml('data-test="court-start-up-next"')
        ->assertDontSeeHtml('data-test="choose-players-button"');
});

test('draft and ended sessions show no court buttons', function () {
    $club = Club::factory()->create();
    $owner = User::factory()->create();
    $club->users()->attach($owner, ['role' => 'owner']);

    foreach ([PlaySession::factory()->for($club)->create(['courts' => 2]), PlaySession::factory()->for($club)->ended()->create(['courts' => 2])] as $session) {
        $match = GameMatch::factory()->create(['play_session_id' => $session->id, 'status' => MatchStatus::Staged]);

        Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
            ->assertDontSeeHtml('data-test="court-start-up-next"')
            ->assertDontSeeHtml('data-test="choose-players-button"')
            ->assertDontSeeHtml('data-test="choose-matchup-modal"');

        Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])->call('startOnCourt', 1, $match->id)->assertHasErrors();
        expect($match->fresh()->status)->toBe(MatchStatus::Staged);
    }
});

test('start on a court is refused for a match of another session', function () {
    [$session] = board(4, ['courts' => 2]);
    $owner = cpOwner($session);
    $foreign = GameMatch::factory()->create();

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->call('startOnCourt', 1, $foreign->id)
        ->assertHasErrors('match');

    expect($foreign->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a user without manage cannot start on a court', function () {
    [$session] = board(4, ['courts' => 2]);
    $owner = cpOwner($session);
    [$match] = cpStaged($session);

    Livewire::actingAs(User::factory()->create())->test(Courts::class, ['session' => $session])->assertNotFound();

    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);
    $session->club->users()->detach($owner->id);
    User::flushRoleCache();

    $component->call('startOnCourt', 1, $match->id)->assertNotFound();

    expect($match->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a stale tap on a free court shows the board error, closes the modal and refreshes the board', function () {
    [$session] = board(8, ['courts' => 2, 'up_next_count' => 2]);
    $owner = cpOwner($session);
    [$first, $second] = cpStaged($session);

    $component = Livewire::actingAs($owner)->test(Courts::class, ['session' => $session]);
    app(MatchService::class)->startMatch($session->fresh(), $first, 2);

    $component->call('startOnCourt', 2, $second->id)
        ->assertHasErrors('court')
        ->assertSeeHtml('data-test="board-error"')
        ->assertSee('That court is in use')
        ->assertDispatched('modal-close', name: 'choose-matchup-2')
        ->assertDispatched('board-changed');

    expect($second->fresh()->status)->toBe(MatchStatus::Staged);
});

test('the up next card refuses to start and shows no courts when the session is not live', function () {
    $club = Club::factory()->create();
    $owner = User::factory()->create();
    $club->users()->attach($owner, ['role' => 'owner']);

    foreach ([PlaySession::factory()->for($club)->create(['courts' => 2]), PlaySession::factory()->for($club)->ended()->create(['courts' => 2])] as $session) {
        $match = GameMatch::factory()->create(['play_session_id' => $session->id, 'status' => MatchStatus::Staged]);

        Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
            ->assertDontSeeHtml('data-test="send-to-court-option"')
            ->assertSee('Session is not live')
            ->call('start', $match->id, 1)
            ->assertHasErrors();

        expect($match->fresh()->status)->toBe(MatchStatus::Staged);
    }
});
