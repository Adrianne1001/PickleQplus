<?php

use App\Domain\Rotation\SkillGroups;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Livewire\Public\Queue;
use App\Livewire\Public\Tv;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\UpNext;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\MatchService;
use Livewire\Livewire;

function uiSkillBoard(array $attrs = []): PlaySession
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

/** @param  list<int>  $stars */
function uiSkillJoin(PlaySession $session, array $stars): void
{
    foreach ($stars as $s) {
        $player = Player::factory()->for($session->club)->manual($s)->create();
        app(CheckInService::class)->checkIn($session, $player);
        test()->travel(1)->seconds();
    }
}

/** @return list<GameMatch> */
function uiSkillMatches(PlaySession $session, MatchStatus $status): array
{
    return GameMatch::query()->where('play_session_id', $session->id)->where('status', $status->value)->orderBy('id')->get()->all();
}

function uiSkillOwner(PlaySession $session): User
{
    $owner = User::factory()->create();
    $session->club->users()->attach($owner, ['role' => 'owner']);

    return $owner;
}

// --- settings form ---

test('switching a new session to skill courts prefills the default groups and shows the help line', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create(['default_courts' => 5]);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertDontSeeHtml('data-test="skill-groups-editor"')
        ->set('rotation_mode', 'skill_courts')
        ->assertSeeHtml('data-test="skill-groups-editor"')
        ->assertSee('Courts only take matches from their own group, even when idle.')
        ->assertSet('skill_groups', [
            ['from_court' => '1', 'to_court' => '3', 'min_stars' => '4', 'max_stars' => '6'],
            ['from_court' => '4', 'to_court' => '5', 'min_stars' => '1', 'max_stars' => '3'],
        ]);
});

test('group rows can be added and removed, and saving stores them', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create(['default_courts' => 6]);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('rotation_mode', 'skill_courts')
        ->call('removeGroup', 1)
        ->assertCount('skill_groups', 1)
        ->call('addGroup')
        ->assertCount('skill_groups', 2)
        ->set('skill_groups.0.to_court', '2')
        ->set('skill_groups.1.from_court', '3')
        ->set('skill_groups.1.to_court', '6')
        ->set('skill_groups.1.min_stars', '1')
        ->set('skill_groups.1.max_stars', '3')
        ->call('save')
        ->assertHasNoErrors();

    $session = PlaySession::query()->where('club_id', $club->id)->latest('id')->first();
    expect($session->mode_settings['skill_groups'])->toBe([
        ['from_court' => 1, 'to_court' => 2, 'min_stars' => 4, 'max_stars' => 6],
        ['from_court' => 3, 'to_court' => 6, 'min_stars' => 1, 'max_stars' => 3],
    ]);
});

test('group validation errors show beside the editor', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create(['default_courts' => 4]);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('rotation_mode', 'skill_courts')
        ->set('skill_groups.1.min_stars', '3')
        ->call('save')
        ->assertHasErrors('mode_settings.skill_groups')
        ->assertSee('Star ranges must not overlap and must cover 1 to 6 stars.');

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('rotation_mode', 'skill_courts')
        ->call('removeGroup', 1)
        ->call('save')
        ->assertSee('Skill courts needs at least 2 groups.');
});

test('editing the groups of a live skill session asks for confirmation', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);

    $component = Livewire::actingAs($owner)->test(Form::class, ['club' => $session->club, 'session' => $session])
        ->assertSet('rotation_mode', 'skill_courts')
        ->assertCount('skill_groups', 2)
        ->set('skill_groups.0.to_court', '1')
        ->set('skill_groups.1.from_court', '2')
        ->call('save')
        ->assertSet('confirmingMode', true);

    expect($session->fresh()->mode_settings['skill_groups'][0]['to_court'])->toBe(2);

    $component->call('confirmModeChange')->assertHasNoErrors();

    expect($session->fresh()->mode_settings['skill_groups'][0]['to_court'])->toBe(1);
});

test('saving unchanged groups on a live skill session needs no confirmation', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $session->club, 'session' => $session])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertSet('confirmingMode', false)
        ->assertHasNoErrors();
});

test('the group editor stays out of the way in other modes', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->live()->create();
    $owner = uiSkillOwner($session);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $session->club, 'session' => $session])
        ->assertDontSeeHtml('data-test="skill-groups-editor"')
        ->assertSet('skill_groups', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($session->fresh()->rotation_mode->value)->toBe('balanced');
});

// --- staff board ---

test('up next and the waiting list are grouped with labels, stars and counts', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 2, 6, 1, 4, 3, 5, 2, 6]);
    $owner = uiSkillOwner($session);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->assertSeeHtml('data-test="up-next-group"')
        ->assertSee('Courts 1–2')
        ->assertSee('Courts 3–4')
        ->assertSee('★4–6')
        ->assertSee('★1–3');

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->assertSeeHtml('data-test="waiting-group"')
        ->assertSee('Courts 1–2')
        ->assertSee('★4–6');
});

test('waiting positions count within each group', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 5, 5, 5, 5, 6, 2, 2, 2, 2, 2, 1]);
    $owner = uiSkillOwner($session);

    $html = Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])->html();

    // 2 waiting in the top group and 2 in the bottom group: both lists start at 1 and end at 2.
    preg_match_all('/data-test="waiting-position"[^>]*>(\d+)</', $html, $m);
    expect($m[1])->toBe(['1', '2', '1', '2']);
});

test('court cards show their group and the start picker lists only free courts of the match group', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);
    uiSkillJoin($session, [5, 5, 5, 5]);
    app(MatchService::class)->startMatch($session, uiSkillMatches($session, MatchStatus::Staged)[0], null);
    uiSkillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2]);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertSeeHtml('data-test="court-group"')
        ->assertSee('Courts 1–2');

    $html = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])->html();
    // Group 1 has court 2 free only; group 2 offers courts 3 and 4.
    $sections = preg_split('/data-test="up-next-group"/', $html);
    expect($sections)->toHaveCount(3);
    expect($sections[1])->toContain('value="2"')->not->toContain('value="1"')->not->toContain('value="3"');
    expect($sections[2])->toContain('value="3"')->toContain('value="4"')->not->toContain('value="2"');
});

test('a court error from the service shows in the board callout', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);
    uiSkillJoin($session, [5, 5, 5, 5, 5, 5, 5, 5]);
    $matches = uiSkillMatches($session, MatchStatus::Staged);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->set("courtChoice.{$matches[0]->id}", '4')
        ->call('start', $matches[0]->id)
        ->assertHasErrors('court')
        ->assertSee("That court is not in this match's skill group.");
});

// --- TV and public queue ---

test('the tv and public queue group by label and never show stars', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 2, 6, 1, 4, 3, 5, 2, 6, 3, 1]);

    $tv = Livewire::test(Tv::class, ['club' => $session->club, 'tvId' => $session->tv_id])
        ->assertSee('Skill courts')
        ->assertSee('Courts 1–2')
        ->assertSee('Courts 3–4')
        ->html();
    $queue = Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])
        ->assertSee('Courts 1–2')
        ->assertSee('Courts 3–4')
        ->html();

    foreach ([$tv, $queue] as $html) {
        expect($html)->not->toContain('★')->not->toContain('stars')->not->toContain('star-rating');
    }
});

test('tv and queue waiting positions count within each group', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 5, 5, 5, 5, 6, 2, 2, 2, 2, 2, 1]);

    $html = Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])->html();
    preg_match_all('/data-test="waiting-position"[^>]*>(\d+)</', $html, $m);
    expect($m[1])->toBe(['1', '2', '1', '2']);

    $tv = Livewire::test(Tv::class, ['club' => $session->club, 'tvId' => $session->tv_id])->html();
    preg_match_all('/data-test="waiting-position"[^>]*>(\d+)</', $tv, $m);
    expect($m[1])->toBe(['1', '2', '1', '2']);
});

test('court cards on the tv and queue show their group', function () {
    $session = uiSkillBoard();

    Livewire::test(Tv::class, ['club' => $session->club, 'tvId' => $session->tv_id])->assertSeeHtml('data-test="tv-court-group"');
    Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])->assertSeeHtml('data-test="queue-court-group"');
});

test('balanced board, tv and queue have no group UI', function () {
    [$session] = board(8);
    $owner = uiSkillOwner($session);

    foreach ([UpNext::class, Courts::class, WaitingList::class] as $component) {
        Livewire::actingAs($owner)->test($component, ['session' => $session])
            ->assertDontSeeHtml('data-test="group-header"')
            ->assertDontSeeHtml('data-test="court-group"')
            ->assertDontSeeHtml('data-test="up-next-group"')
            ->assertDontSeeHtml('data-test="waiting-group"');
    }

    Livewire::test(Tv::class, ['club' => $session->club, 'tvId' => $session->tv_id])
        ->assertDontSeeHtml('data-test="tv-group-label"')
        ->assertDontSee('Skill courts');
    Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])
        ->assertDontSeeHtml('data-test="queue-group-label"')
        ->assertDontSee('Skill courts');
});

test('two groups each pick a court and start independently', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);
    uiSkillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2]);
    [$top, $bottom] = uiSkillMatches($session, MatchStatus::Staged);

    $component = Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->set("courtChoice.{$top->id}", '2')
        ->set("courtChoice.{$bottom->id}", '4')
        ->call('start', $bottom->id)
        ->assertHasNoErrors();

    expect($bottom->fresh()->court_no)->toBe(4)->and($top->fresh()->status)->toBe(MatchStatus::Staged);

    $component->set("courtChoice.{$top->id}", '2')->call('start', $top->id)->assertHasNoErrors();

    expect($top->fresh()->court_no)->toBe(2);
});

test('a court picked for one match is not used for another and a bad shape is rejected', function () {
    $session = uiSkillBoard();
    $owner = uiSkillOwner($session);
    uiSkillJoin($session, [5, 5, 5, 5, 2, 2, 2, 2]);
    [$top, $bottom] = uiSkillMatches($session, MatchStatus::Staged);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->set("courtChoice.{$top->id}", '2')
        ->call('start', $bottom->id)
        ->assertHasNoErrors();

    expect($bottom->fresh()->court_no)->toBe(3);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->set('courtChoice', ['x' => '1'])
        ->call('start', $top->id)
        ->assertHasErrors('court');
});

test('the queue me-card position uses the group position in skill courts', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 5, 5, 5, 5, 5, 2, 2, 2, 2, 2]);

    $live = Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])->get('live');

    $positions = collect($live['waiting'])->pluck('position')->all();
    expect($positions)->toBe([1, 2, 1]);
});

test('the public snapshot carries group_position and the queue renders it', function () {
    $session = uiSkillBoard();
    uiSkillJoin($session, [5, 5, 5, 5, 5, 5, 2, 2, 2, 2, 2]);

    $component = Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id]);

    $waiting = $component->viewData('data')['waiting'];
    expect(collect($waiting)->pluck('group_position')->all())->toBe([1, 2, 1])
        ->and(collect($waiting)->pluck('position')->all())->not->toBe([1, 2, 1]);

    preg_match_all('/data-test="waiting-position"[^>]*>(\d+)</', $component->html(), $m);
    expect($m[1])->toBe(['1', '2', '1']);
});

test('editing a skill session with invalid stored groups prefills the defaults', function () {
    $session = uiSkillBoard();
    $session->update(['mode_settings' => ['skill_groups' => [['label' => 'Broken']]]]);
    $owner = uiSkillOwner($session);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $session->club, 'session' => $session])
        ->assertCount('skill_groups', 2);
});
