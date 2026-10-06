<?php

use App\Enums\LateArrivalPolicy;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\Show;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\PlaySessionService;
use Livewire\Livewire;

function sessionClubs(): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create(['default_courts' => 6]);

    return [$owner, $staff, $club];
}

// --- access ---

test('owners and staff can open the session pages', function () {
    [$owner, $staff, $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get(route('clubs.sessions.index', $club))->assertOk();
        $this->actingAs($user)->get(route('clubs.sessions.create', $club))->assertOk();
        $this->actingAs($user)->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->assertSee($session->name);
        $this->actingAs($user)->get(route('clubs.sessions.edit', [$club, $session]))->assertOk();
    }
});

test('non-members and cross-club sessions are 404', function () {
    [$owner, , $club] = sessionClubs();
    $stranger = User::factory()->create();
    $session = PlaySession::factory()->for($club)->create();
    $otherClub = Club::factory()->withOwner($owner)->create();

    $this->actingAs($stranger)->get(route('clubs.sessions.index', $club))->assertNotFound();
    $this->actingAs($stranger)->get(route('clubs.sessions.show', [$club, $session]))->assertNotFound();
    // Right user, right club in the URL, but the session belongs to another club.
    $this->actingAs($owner)->get(route('clubs.sessions.show', [$otherClub, $session]))->assertNotFound();
    $this->actingAs($owner)->get(route('clubs.sessions.edit', [$otherClub, $session]))->assertNotFound();
});

test('session settings on the club settings page are owner-only', function () {
    [$owner, $staff, $club] = sessionClubs();

    $this->actingAs($staff)->get(route('clubs.settings', $club))->assertForbidden();

    $this->actingAs($owner)->get(route('clubs.settings', $club))->assertOk()->assertSee('Allow several live sessions at once');

    Livewire::actingAs($staff)->test('pages::clubs.settings', ['club' => $club])->assertForbidden();
});

test('the owner saves the session settings', function () {
    [$owner, , $club] = sessionClubs();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->assertSet('late_arrival_policy', 'minimum')
        ->assertSet('allow_concurrent_sessions', false)
        ->set('late_arrival_policy', 'back')
        ->set('allow_concurrent_sessions', true)
        ->call('saveSessionSettings')
        ->assertHasNoErrors();

    $club->refresh();
    expect($club->late_arrival_policy)->toBe(LateArrivalPolicy::Back)
        ->and($club->allow_concurrent_sessions)->toBeTrue();
});

test('an invalid late arrival policy is rejected', function () {
    [$owner, , $club] = sessionClubs();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->set('late_arrival_policy', 'sideways')
        ->call('saveSessionSettings')
        ->assertHasErrors('late_arrival_policy');
});

// --- list ---

test('the list shows live sessions first, then drafts, then ended, with counts', function () {
    [$owner, , $club] = sessionClubs();
    PlaySession::factory()->for($club)->ended()->create(['name' => 'C ended']);
    PlaySession::factory()->for($club)->create(['name' => 'B draft']);
    $live = PlaySession::factory()->for($club)->live()->create(['name' => 'A live', 'courts' => 3]);
    SessionPlayer::factory()->for($live, 'playSession')->count(2)->create(['player_id' => fn () => Player::factory()->for($club)->create()->id]);
    SessionPlayer::factory()->for($live, 'playSession')->status(SessionPlayerStatus::Left)->create(['player_id' => Player::factory()->for($club)->create()->id]);
    PlaySession::factory()->create(['name' => 'Other club']);

    Livewire::actingAs($owner)->test(Index::class, ['club' => $club])
        ->assertSeeInOrder(['A live', 'B draft', 'C ended'])
        ->assertDontSee('Other club')
        ->assertSee('3 courts')
        ->assertSee('2 checked in');
});

// --- create / edit ---

test('the create form is prefilled from the club defaults', function () {
    [$owner, , $club] = sessionClubs();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSet('courts', '6')
        ->assertSet('up_next_count', '1')
        ->assertSet('auto_fill', false)
        ->assertSet('to', '11')
        ->assertSet('win_by', '2');
});

test('creating a session saves a draft in the club and redirects to it', function () {
    [, $staff, $club] = sessionClubs();

    Livewire::actingAs($staff)->test(Form::class, ['club' => $club])
        ->set('name', 'Friday night')
        ->set('courts', '8')
        ->set('up_next_count', '2')
        ->set('auto_fill', true)
        ->set('to', '15')
        ->set('win_by', '1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $session = $club->playSessions()->firstOrFail();
    expect($session->name)->toBe('Friday night')
        ->and($session->status)->toBe(SessionStatus::Draft)
        ->and($session->courts)->toBe(8)
        ->and($session->up_next_count)->toBe(2)
        ->and($session->auto_fill)->toBeTrue()
        ->and($session->scoring)->toMatchArray(['to' => 15, 'win_by' => 1]);
});

test('create validation errors are shown', function (string $field, string $value) {
    [$owner, , $club] = sessionClubs();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field === 'to' ? 'scoring.to' : $field]);

    expect($club->playSessions()->count())->toBe(0);
})->with([
    'no courts' => ['courts', '0'],
    'too many courts' => ['courts', '51'],
    'text courts' => ['courts', 'many'],
    'up next 4' => ['up_next_count', '4'],
    'bad score' => ['to', '12'],
    'empty name' => ['name', ''],
]);

test('editing prefills and updates, and an ended session is disabled', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create(['courts' => 3, 'scoring' => ['type' => 'side_out', 'games' => 1, 'to' => 21, 'win_by' => 1]]);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSet('courts', '3')
        ->assertSet('to', '21')
        ->set('courts', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect($session->fresh()->courts)->toBe(5);

    $ended = PlaySession::factory()->for($club)->ended()->create();
    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $ended])
        ->assertSee('can no longer be edited')
        ->assertDontSeeHtml('data-test="save-session-button"')
        ->set('courts', '2')
        ->call('save')
        ->assertHasErrors('status');
});

// --- lifecycle on the session page ---

test('start, end and delete go through the service', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();

    $page = Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('data-test="start-session-button"')
        ->call('start')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="start-session-button"')
        ->assertSeeHtml('data-test="end-session-button"');
    expect($session->fresh()->status)->toBe(SessionStatus::Live);

    $page->call('end')->assertHasNoErrors();
    expect($session->fresh()->status)->toBe(SessionStatus::Ended);

    $draft = PlaySession::factory()->for($club)->create();
    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $draft])
        ->call('delete')
        ->assertRedirect(route('clubs.sessions.index', $club));
    expect(PlaySession::find($draft->id))->toBeNull();
});

test('only a draft shows the delete button, and end/delete need confirmation modals', function () {
    [$owner, , $club] = sessionClubs();
    $live = PlaySession::factory()->for($club)->live()->create();

    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $live])
        ->assertDontSeeHtml('data-test="delete-session-button"')
        ->assertSeeHtml('data-test="confirm-end-session-button"')
        ->assertSeeHtml('data-test="confirm-delete-session-button"');
});

test('starting while another session is live shows the service error', function () {
    [$owner, , $club] = sessionClubs();
    PlaySession::factory()->for($club)->live()->create();
    $draft = PlaySession::factory()->for($club)->create();

    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $draft])
        ->call('start')
        ->assertHasErrors('status')
        ->assertSee('Another session is already live');

    expect($draft->fresh()->status)->toBe(SessionStatus::Draft);
});

test('deleting a live session shows the service error', function () {
    [$owner, , $club] = sessionClubs();
    $live = PlaySession::factory()->for($club)->live()->create();

    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $live])
        ->call('delete')
        ->assertHasErrors('status');

    expect(PlaySession::find($live->id))->not->toBeNull();
});

// --- check-in panel ---

test('the search lists only active, not-yet-checked-in club players', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    Player::factory()->for($club)->create(['name' => 'Alice Active']);
    Player::factory()->for($club)->create(['name' => 'Alan Inactive', 'active' => false]);
    $in = Player::factory()->for($club)->create(['name' => 'Alma Already']);
    SessionPlayer::factory()->for($session, 'playSession')->create(['player_id' => $in->id]);
    Player::factory()->create(['name' => 'Al Elsewhere']);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->set('search', 'Al')
        ->assertSee('Alice Active')
        ->assertDontSee('Alan Inactive')
        ->assertDontSee('Al Elsewhere')
        ->assertSeeInOrder(['Checked in (1)', 'Alma Already']);
});

test('check in, break, return and check out update the list', function () {
    [, $staff, $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    $player = Player::factory()->for($club)->create(['name' => 'Pat Player']);

    $panel = Livewire::actingAs($staff)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkIn', $player->id)
        ->assertHasNoErrors()
        ->assertSet('search', '')
        ->assertSee('Pat Player');
    expect(SessionPlayer::where('player_id', $player->id)->value('status'))->toBe(SessionPlayerStatus::Waiting);

    $panel->call('goOnBreak', $player->id)->assertHasNoErrors();
    expect(SessionPlayer::where('player_id', $player->id)->value('status'))->toBe(SessionPlayerStatus::Break);
    $panel->assertSeeHtml('data-test="return-button"');

    $panel->call('returnFromBreak', $player->id)->assertHasNoErrors();
    expect(SessionPlayer::where('player_id', $player->id)->value('status'))->toBe(SessionPlayerStatus::Waiting);

    $panel->call('checkOut', $player->id)->assertHasNoErrors()->assertDontSee('Pat Player');
    expect(SessionPlayer::where('player_id', $player->id)->value('status'))->toBe(SessionPlayerStatus::Left);
});

test('checking out a playing player is blocked and the error is shown', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->live()->create();
    $player = Player::factory()->for($club)->create();
    SessionPlayer::factory()->for($session, 'playSession')->status(SessionPlayerStatus::Playing)->create(['player_id' => $player->id]);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkOut', $player->id)
        ->assertHasErrors('player')
        ->assertSee('Swap them out first');

    expect(SessionPlayer::where('player_id', $player->id)->value('status'))->toBe(SessionPlayerStatus::Playing);
});

test('a player from another club or a stale id shows an inline error', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    $foreign = Player::factory()->create();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkIn', $foreign->id)
        ->assertHasErrors('player');

    expect(SessionPlayer::count())->toBe(0);
});

test('an ended session hides the check-in controls and refuses check-ins', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->ended()->create();
    $player = Player::factory()->for($club)->create();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="check-in-search"')
        ->call('checkIn', $player->id)
        ->assertHasErrors('session');
});

test('the sidebar links to sessions', function () {
    [$owner, , $club] = sessionClubs();

    $this->actingAs($owner)->get(route('clubs.sessions.index', $club))
        ->assertSee(route('clubs.sessions.index', $club), false);
});

test('a stranger or a member of another club cannot mount the session components', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    $stranger = User::factory()->create();
    $otherOwner = User::factory()->create();
    Club::factory()->withOwner($otherOwner)->create();

    foreach ([$stranger, $otherOwner] as $user) {
        Livewire::actingAs($user)->test(CheckInPanel::class, ['session' => $session])->assertNotFound();
        Livewire::actingAs($user)->test(Show::class, ['club' => $club, 'session' => $session])->assertNotFound();
    }
});

test('a session from another club is a 404 when mounted with the wrong club', function () {
    [$owner, , $club] = sessionClubs();
    $other = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($other)->create();

    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $session])->assertNotFound();
    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])->assertNotFound();
});

test('actions are denied after membership is removed', function () {
    [, $staff, $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    $player = Player::factory()->for($club)->create();

    $panel = Livewire::actingAs($staff)->test(CheckInPanel::class, ['session' => $session]);
    $show = Livewire::actingAs($staff)->test(Show::class, ['club' => $club, 'session' => $session]);

    $club->users()->detach($staff->id);
    User::flushRoleCache();

    $panel->call('checkIn', $player->id)->assertNotFound();
    $show->call('start')->assertNotFound();

    expect(SessionPlayer::count())->toBe(0)
        ->and($session->fresh()->status)->toBe(SessionStatus::Draft);
});

test('staff cannot call saveSessionSettings', function () {
    [, $staff, $club] = sessionClubs();
    $component = Livewire::actingAs($staff)->test('pages::clubs.settings', ['club' => $club]);
    $component->assertForbidden();
});

test('a stale page cannot start an ended session', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->live()->create();

    $page = Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $session]);
    app(PlaySessionService::class)->end(PlaySession::findOrFail($session->id));

    $page->call('start')->assertHasErrors('status')->assertSee('Only a draft session can be started');
    expect($session->fresh()->status)->toBe(SessionStatus::Ended);
});

test('a stale panel cannot check in to an ended session', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->live()->create();
    $player = Player::factory()->for($club)->create();

    $panel = Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session]);
    app(PlaySessionService::class)->end(PlaySession::findOrFail($session->id));

    $panel->call('checkIn', $player->id)->assertHasErrors('session')->assertSee('This session has ended');
    expect(SessionPlayer::count())->toBe(0)
        ->and($session->fresh()->status)->toBe(SessionStatus::Ended);
});

test('a deactivated player id shows an inline error', function () {
    [$owner, , $club] = sessionClubs();
    $session = PlaySession::factory()->for($club)->create();
    $player = Player::factory()->for($club)->create(['active' => false]);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkIn', $player->id)
        ->assertHasErrors('player');
});
