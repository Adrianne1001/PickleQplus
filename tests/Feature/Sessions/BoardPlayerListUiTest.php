<?php

use App\Livewire\Public\CheckIn;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\CheckInQr;
use App\Livewire\Sessions\ImportPlayers;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\SessionListService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/** @return array{0: User, 1: Club, 2: PlaySession} */
function uiBoard(string $state = 'live'): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    return [$owner, $club, PlaySession::factory()->for($club)->{$state}()->create()];
}

test('the panel shows the Add players card, Not Yet Here and the N/M count', function () {
    [$owner, $club, $session] = uiBoard();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);
    $bea = Player::factory()->for($club)->create(['name' => 'Bea Cruz']);
    listPlayers($session, $ana, $bea);
    app(CheckInService::class)->checkIn($session, $bea);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('data-test="add-players-card"')
        ->assertSeeHtml('data-test="open-import-button"')
        ->assertSee('Import player list')
        ->assertSeeHtml('placeholder="Search or add player…"')
        ->assertSee('Not Yet Here (1)')
        ->assertSeeHtml('data-test="not-yet-here-card"')
        ->assertSeeHtml('data-test="check-in-all-button"')
        ->assertSeeHtml('data-test="remove-from-list-button"')
        ->assertSeeHtml('data-test="checked-in-bar"')
        ->assertSee('1/2 checked in')
        ->assertSeeHtml('data-test="checked-in-count"')
        ->assertSeeHtml('data-test="checked-in-list"');
});

test('an empty Not Yet Here list points to the next step and has no Check in all', function () {
    [$owner, , $session] = uiBoard();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSee('Not Yet Here (0)')
        ->assertSeeHtml('data-test="not-yet-here-empty"')
        ->assertDontSeeHtml('data-test="check-in-all-button"')
        ->assertDontSeeHtml('data-test="checked-in-bar"');
});

test('Check In moves a player from Not Yet Here to the checked-in list', function () {
    [$owner, $club, $session] = uiBoard();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);
    listPlayers($session, $ana);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSee('Not Yet Here (1)')
        ->call('checkIn', $ana->id)
        ->assertHasNoErrors()
        ->assertSee('Not Yet Here (0)')
        ->assertSee('Checked in (1)')
        ->assertSee('1/1 checked in');

    expect(SessionPlayer::where('play_session_id', $session->id)->where('player_id', $ana->id)->exists())->toBeTrue();
});

test('Check in all checks everyone in and names the players it skipped with the reason', function () {
    [$owner, $club, $session] = uiBoard();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);
    $bea = Player::factory()->for($club)->create(['name' => 'Bea Cruz']);
    $busy = Player::factory()->for($club)->create(['name' => 'Cal Busy']);
    listPlayers($session, $ana, $bea, $busy);
    $other = PlaySession::factory()->for($club)->live()->create();
    app(CheckInService::class)->checkIn($other, $busy);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkInAll')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="check-in-all-result"')
        ->assertSee('2 players checked in.')
        ->assertSee('Skipped:')
        ->assertSeeHtml('data-test="check-in-all-skipped"')
        ->assertSee('This player is already in another live session.')
        ->assertSee('Cal Busy')
        ->assertSee('Not Yet Here (1)')
        ->call('dismissResults')
        ->assertDontSeeHtml('data-test="check-in-all-result"');

    expect(SessionPlayer::where('play_session_id', $session->id)->count())->toBe(2);
});

test('Check in all with nobody to check in says zero', function () {
    [$owner, , $session] = uiBoard();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('checkInAll')
        ->assertSee('0 players checked in.');
});

test('Remove takes a player off the list, and refuses one who is checked in', function () {
    [$owner, $club, $session] = uiBoard();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);
    $bea = Player::factory()->for($club)->create(['name' => 'Bea Cruz']);
    listPlayers($session, $ana, $bea);
    app(CheckInService::class)->checkIn($session, $bea);

    $panel = Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('removeFromList', $ana->id)
        ->assertHasNoErrors()
        ->assertSee('Not Yet Here (0)');

    expect(SessionListPlayer::where('play_session_id', $session->id)->where('player_id', $ana->id)->exists())->toBeFalse();

    $panel->call('removeFromList', $bea->id)
        ->assertHasErrors('player')
        ->assertSeeHtml('data-test="check-in-error"');

    expect(SessionListPlayer::where('play_session_id', $session->id)->where('player_id', $bea->id)->exists())->toBeTrue();
});

test('quick-add with blank stars lists the player at 3 stars and shows Set skill', function () {
    [$owner, $club, $session] = uiBoard();

    $panel = Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->set('search', 'Newbie Nat')
        ->call('openQuickAdd')
        ->assertSee('Stars (optional)')
        ->call('saveQuick')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="skill-pending"')
        ->assertSeeHtml('data-test="set-skill-link"')
        ->assertSee('Set skill');

    $player = Player::where('club_id', $club->id)->where('name', 'Newbie Nat')->firstOrFail();
    expect($player->stars)->toBe(3)->and($player->stars_pending)->toBeTrue()
        ->and(SessionListPlayer::where('player_id', $player->id)->exists())->toBeTrue();

    // With stars chosen the player is not pending.
    $panel->set('search', 'Chosen Cy')->call('openQuickAdd')->set('quick.stars', '5')->call('saveQuick')->assertHasNoErrors();
    expect(Player::where('name', 'Chosen Cy')->firstOrFail()->stars_pending)->toBeFalse();
});

test('Set skill opens the star picker, saves the stars and clears the prompt', function () {
    [$owner, $club, $session] = uiBoard();
    $pending = Player::factory()->for($club)->create(['name' => 'Pat Pending', 'stars' => 3, 'stars_pending' => true]);
    listPlayers($session, $pending);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('data-test="set-skill-link"')
        ->assertDontSeeHtml('data-test="skill-picker"')
        ->call('openSkill', $pending->id)
        ->assertSeeHtml('data-test="skill-picker"')
        ->assertSeeHtml('data-test="star-5"')
        ->assertSeeHtml('data-test="save-skill-button"')
        ->call('saveSkill')
        ->assertHasErrors('stars')
        ->assertSeeHtml('data-test="skill-error"')
        ->set('skillStars', '5')
        ->call('saveSkill')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="skill-picker"')
        ->assertDontSeeHtml('data-test="set-skill-link"');

    expect($pending->fresh()->stars)->toBe(5)->and($pending->fresh()->stars_pending)->toBeFalse();
});

test('Cancel closes the star picker without saving', function () {
    [$owner, $club, $session] = uiBoard();
    $pending = Player::factory()->for($club)->create(['stars' => 3, 'stars_pending' => true]);
    listPlayers($session, $pending);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('openSkill', $pending->id)
        ->set('skillStars', '6')
        ->call('cancelSkill')
        ->assertDontSeeHtml('data-test="skill-picker"')
        ->assertSet('skillStars', '');

    expect($pending->fresh()->stars_pending)->toBeTrue();
});

test('the checked-in list also offers Set skill for a pending player', function () {
    [$owner, $club, $session] = uiBoard();
    $pending = Player::factory()->for($club)->create(['name' => 'Pat Pending', 'stars' => 3, 'stars_pending' => true]);
    listPlayers($session, $pending);
    app(CheckInService::class)->checkIn($session, $pending);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('data-test="checked-in-row"')
        ->assertSeeHtml('data-test="set-skill-link"')
        ->call('openSkill', $pending->id)
        ->assertSeeHtml('data-test="skill-picker"')
        ->set('skillStars', '4')
        ->call('saveSkill')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="set-skill-link"');

    expect($pending->fresh()->stars)->toBe(4);
});

test('the import result shows as a flash that can be dismissed', function () {
    [$owner, , $session] = uiBoard();
    Cache::put(ImportPlayers::resultKey($session, 'tok123'), ['listed' => 8, 'created' => 3, 'already_listed' => 2, 'errors' => 1, 'notes' => ['Zed: a player with that name is already on the roster.']], 300);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->dispatch('players-imported', token: 'tok123')
        ->assertSeeHtml('data-test="import-result"')
        ->assertSee('Added 8 players to the list.')
        ->assertSee('3 new.')
        ->assertSee('2 already on the list.')
        ->assertSee('1 row had an error and was skipped.')
        ->assertSeeHtml('data-test="import-result-note"')
        ->call('dismissResults')
        ->assertDontSeeHtml('data-test="import-result"');

    expect(Cache::has(ImportPlayers::resultKey($session, 'tok123')))->toBeFalse();
});

test('an import event with an unknown or empty token shows nothing', function () {
    [$owner, , $session] = uiBoard();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->dispatch('players-imported', token: 'forged')
        ->assertDontSeeHtml('data-test="import-result"')
        ->dispatch('players-imported', token: '')
        ->assertDontSeeHtml('data-test="import-result"');
});

test('Set skill is not offered on an ended session', function () {
    [$owner, $club, $session] = uiBoard();
    $pending = Player::factory()->for($club)->create(['stars' => 3, 'stars_pending' => true]);
    listPlayers($session, $pending);
    app(CheckInService::class)->checkIn($session, $pending);
    $session->forceFill(['status' => 'ended'])->save();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session->fresh()])
        ->assertSeeHtml('data-test="checked-in-row"')
        ->assertDontSeeHtml('data-test="set-skill-link"');
});

test('the session password never appears on the public check-in page, the TV or the public queue', function () {
    [, $club, $session] = uiBoard();
    app(SessionListService::class)->setPassword($session, 'Kitchen7');
    $player = Player::factory()->for($club)->create(['name' => 'Pam Password']);
    listPlayers($session, $player);
    $session->refresh();

    foreach ([
        '/checkin/'.$session->checkin_token,
        '/c/'.$club->slug.'/s/'.$session->public_id,
        '/c/'.$club->slug.'/tv/'.$session->tv_id,
    ] as $url) {
        test()->get($url)->assertOk()->assertDontSee('Kitchen7');
    }

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Pam')
        ->call('select', $player->public_id)
        ->assertSeeHtml('data-test="checkin-password"')
        ->assertDontSee('Kitchen7')
        ->assertDontSeeHtml('Kitchen7');
});

test('an ended session shows only the checked-in list', function () {
    [$owner, , $session] = uiBoard('ended');

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="add-players-card"')
        ->assertDontSeeHtml('data-test="not-yet-here"')
        ->assertDontSeeHtml('data-test="open-import-button"')
        ->assertSeeHtml('data-test="checked-in-list"');
});

// --- session password on the Check-in share tab ---

test('the Check-in share tab sets, reveals and clears the session password', function () {
    [$owner, , $session] = uiBoard();

    $panel = Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertSeeHtml('data-test="session-password"')
        ->assertSeeHtml('data-test="session-password-none"')
        ->assertDontSeeHtml('data-test="session-password-value"')
        ->set('password', 'abc')
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSee('4 to 32 characters')
        ->set('password', str_repeat('x', 33))
        ->call('savePassword')
        ->assertHasErrors('password')
        ->set('password', '')
        ->call('savePassword')
        ->assertHasErrors('password');

    expect($session->fresh()->checkin_password)->toBeNull();

    $panel->set('password', 'Kitchen7')
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSeeHtml('data-test="session-password-value"')
        ->assertSeeHtml('data-test="session-password-reveal"')
        ->assertSeeHtml('value="Kitchen7"')
        ->assertDontSeeHtml('data-test="session-password-none"');

    expect($session->fresh()->checkin_password)->toBe('Kitchen7');

    $panel->set('password', 'Court99')->call('savePassword')->assertHasNoErrors();
    expect($session->fresh()->checkin_password)->toBe('Court99');

    $panel->call('clearPassword')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="session-password-none"')
        ->assertDontSeeHtml('data-test="session-password-value"');

    expect($session->fresh()->checkin_password)->toBeNull();
});

test('only staff of the club can set the session password', function () {
    [, , $session] = uiBoard();
    $stranger = User::factory()->create();

    $component = Livewire::actingAs($session->club->users()->firstOrFail())->test(CheckInQr::class, ['session' => $session]);
    test()->actingAs($stranger);
    $component->set('password', 'Kitchen7')->call('savePassword')->assertStatus(404);

    expect($session->fresh()->checkin_password)->toBeNull();
});
