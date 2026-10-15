<?php

use App\Livewire\Sessions\Form;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\PlayerListImportService;
use Livewire\Livewire;

/** @return array{0: User, 1: Club} */
function formWorld(): array
{
    $owner = User::factory()->create();

    return [$owner, Club::factory()->withOwner($owner)->create()];
}

/** @return list<array<string, mixed>> */
function formRows(Club $club, string $text, array $alsoListed = []): array
{
    return app(PlayerListImportService::class)->reviewPaste($club, null, $text, $alsoListed);
}

test('the Players section builds a list, says nothing is checked in on save and offers the import', function () {
    [$owner, $club] = formWorld();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSee('Players')
        ->assertSee('Nobody is checked in when you save')
        ->assertDontSee('Everyone is checked in')
        ->assertSeeHtml('data-test="open-import-button"')
        ->assertSee('Import player list')
        ->assertSee('To add (0)')
        ->assertSeeHtml('placeholder="Search or add player…"')
        ->assertSeeHtml('wire:name="sessions.import-players"');
});

test('an import folds reviewed rows into the To add chips, and Save lists them without checking anyone in', function () {
    [$owner, $club] = formWorld();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana']);

    $form = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->dispatch('import-rows', rows: formRows($club, "Ana\nBea | rating=4.2 | duprId=23Q432\nCy"))
        ->assertHasNoErrors()
        ->assertSee('To add (3)')
        ->assertSeeHtml('data-test="picked-chip"')
        ->assertSeeHtml('data-test="new-chip"')
        ->assertSee('new')
        ->assertSee('DUPR 4.200')
        ->assertSeeHtml('data-test="chip-skill-pending"')
        ->assertSeeHtml('data-test="import-summary-note"')
        ->assertSee('Added 3 players from the import.')
        ->assertSet('pickedIds', [$ana->id]);

    expect(Player::where('club_id', $club->id)->where('name', 'Bea')->exists())->toBeFalse();

    $form->call('save')->assertHasNoErrors();

    $session = PlaySession::where('club_id', $club->id)->firstOrFail();
    $bea = Player::where('club_id', $club->id)->where('name', 'Bea')->firstOrFail();
    $cy = Player::where('club_id', $club->id)->where('name', 'Cy')->firstOrFail();
    expect(SessionListPlayer::where('play_session_id', $session->id)->count())->toBe(3)
        ->and(SessionListPlayer::where('play_session_id', $session->id)->where('player_id', $ana->id)->firstOrFail()->source->value)->toBe('paste')
        ->and($bea->stars_pending)->toBeFalse()
        ->and($cy->stars)->toBe(3)->and($cy->stars_pending)->toBeTrue()
        ->and(SessionPlayer::where('play_session_id', $session->id)->exists())->toBeFalse();
});

test('an import skips players already picked and new names already waiting in the chips', function () {
    [$owner, $club] = formWorld();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana']);

    $form = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->call('pick', $ana->id)
        ->set('search', 'Dee')->call('openQuickAdd')->set('quick.stars', '4')->call('addQuick')
        ->dispatch('import-rows', rows: formRows($club, "Ana\nDee\nEve", [$ana->id]))
        ->assertSee('1 already on the list.')
        ->assertSee('1 skipped because a new player with that name was already added.')
        ->assertSee('To add (3)');

    expect($form->get('newPlayers'))->toHaveCount(2)->and($form->get('pickedIds'))->toBe([$ana->id]);
});

test('import rows are re-validated on the server, so forged rows add nothing', function () {
    [$owner, $club] = formWorld();
    $foreign = Player::factory()->for(Club::factory()->create())->create(['name' => 'Foreign Fran']);

    $form = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->dispatch('import-rows', rows: [['name' => 'Zed', 'rating' => '', 'dupr_id' => '', 'source' => 'paste', 'status' => 'existing', 'player_id' => $foreign->id]]);

    expect($form->get('pickedIds'))->toBe([])->and($form->get('newPlayers'))->toHaveCount(1);
});

test('quick-add with blank stars then Save lists the player at 3 stars with skill not set', function () {
    [$owner, $club] = formWorld();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Skill Sam')
        ->call('openQuickAdd')
        ->assertSee('Stars (optional)')
        ->call('addQuick')
        ->assertHasNoErrors()
        ->assertSee('To add (1)')
        ->assertSeeHtml('data-test="chip-skill-pending"')
        ->call('save')
        ->assertHasNoErrors();

    $player = Player::where('club_id', $club->id)->where('name', 'Skill Sam')->firstOrFail();
    expect($player->stars)->toBe(3)->and($player->stars_pending)->toBeTrue()
        ->and(SessionListPlayer::where('player_id', $player->id)->exists())->toBeTrue();
});

test('the Add to list button on a search result is labelled for the list', function () {
    [$owner, $club] = formWorld();
    Player::factory()->for($club)->create(['name' => 'Ana Lopez']);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Ana')
        ->assertSee('Add to list')
        ->assertSeeHtml('data-test="pick-player"');
});

test('the optional check-in password is validated inline, saved, shown on edit and cleared by blanking it', function () {
    [$owner, $club] = formWorld();

    $form = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSeeHtml('data-test="checkin-password-input"')
        ->assertSee('Players need it to check in from the QR code')
        ->set('checkin_password', 'abc')
        ->call('save')
        ->assertHasErrors('password');

    expect(PlaySession::where('club_id', $club->id)->exists())->toBeFalse();

    $form->set('checkin_password', 'Kitchen7')->call('save')->assertHasNoErrors();
    $session = PlaySession::where('club_id', $club->id)->firstOrFail();
    expect($session->checkin_password)->toBe('Kitchen7');

    $edit = Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSet('checkin_password', 'Kitchen7')
        ->set('checkin_password', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($session->fresh()->checkin_password)->toBeNull();
});

test('saving without touching the password keeps it', function () {
    [$owner, $club] = formWorld();
    $session = PlaySession::factory()->for($club)->create(['checkin_password' => 'Kitchen7']);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($session->fresh()->checkin_password)->toBe('Kitchen7')->and($session->fresh()->name)->toBe('Renamed');
});

test('the edit page shows the saved list read-only with counts and a link to the board', function () {
    [$owner, $club] = formWorld();
    $session = PlaySession::factory()->for($club)->live()->create();
    $in = Player::factory()->for($club)->create(['name' => 'Ana In']);
    $waiting = Player::factory()->for($club)->create(['name' => 'Bea Waiting']);
    listPlayers($session, $in, $waiting);
    app(CheckInService::class)->checkIn($session, $in);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('data-test="existing-list"')
        ->assertSee('Already on the list (2)')
        ->assertSee('1 checked in, 1 not yet here')
        ->assertSee('Ana In')
        ->assertSee('Bea Waiting')
        ->assertSeeHtml('data-test="open-board-link"')
        ->assertSee('Open board to check in')
        ->assertSee(route('clubs.sessions.show', [$club, $session]));
});

test('the import is hidden when the session has ended', function () {
    [$owner, $club] = formWorld();
    $session = PlaySession::factory()->for($club)->ended()->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertDontSeeHtml('data-test="open-import-button"')
        ->assertDontSeeHtml('wire:name="sessions.import-players"')
        ->assertDontSeeHtml('data-test="checkin-password-input"');

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->dispatch('import-rows', rows: [])
        ->assertForbidden();
});
