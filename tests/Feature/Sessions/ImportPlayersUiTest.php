<?php

use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\ImportPlayers;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportReactiveProps\SupportReactiveProps;
use Livewire\Livewire;

/** @return array{0: User, 1: Club, 2: PlaySession} */
function importWorld(): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    return [$owner, $club, PlaySession::factory()->for($club)->live()->create()];
}

function importList(User $owner, Club $club, ?PlaySession $session, string $mode = 'board')
{
    return Livewire::actingAs($owner)->test(ImportPlayers::class, ['club' => $club, 'session' => $session, 'mode' => $mode]);
}

test('the modal has both tabs, the helper copy and a disabled-while-empty Review button', function () {
    [$owner, $club, $session] = importWorld();

    importList($owner, $club, $session)
        ->assertSee('Import player list')
        ->assertSeeHtml('data-test="import-tab-paste"')
        ->assertSeeHtml('data-test="import-tab-reclub"')
        ->assertSee('Paste list')
        ->assertSee('Reclub link')
        ->assertSee('Copy from a group chat, notes, or Reclub. One player per line. Numbers are optional.')
        ->assertSeeHtml('placeholder="1. Ana / 2. Miguel / 3. Bea"')
        ->assertSee('Include ratings or DUPR IDs')
        ->assertSee('Add details after each name. You can review and edit them before adding players.')
        ->assertSee('Ana | rating=4.2 | duprId=23Q432')
        ->assertSeeHtml('data-test="import-review-button"')
        ->assertSeeHtml("x-bind:disabled=\"! (\$wire.text ?? '').trim()\"")
        ->assertSee('Paste your Reclub event link.')
        ->assertSeeHtml('placeholder="https://reclub.co/m/YCFQJY"')
        ->assertSee("You'll review the confirmed players before adding them.")
        ->assertSeeHtml('data-test="import-reclub-button"')
        ->assertSeeHtml('data-test="import-reclub-loading"')
        ->assertSee('Load players')
        ->assertSee('Cancel');
});

test('paste, review, then add: outcomes, summary and the confirm result', function () {
    [$owner, $club, $session] = importWorld();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana']);
    Player::factory()->for($club)->create(['name' => 'Miguel']);
    listPlayers($session, $ana);

    $component = importList($owner, $club, $session)
        ->set('text', "1. Ana\n2) Miguel\n- Bea | rating=4.2 | duprId=23Q432\n#4 Cy")
        ->call('reviewPaste')
        ->assertHasNoErrors()
        ->assertSet('step', 'review')
        ->assertSeeHtml('data-test="import-review"')
        ->assertSee('2 new, 1 existing, 1 already listed')
        ->assertSee('Add 3 players')
        ->assertSee('Already on list')
        ->assertSee('Existing player')
        ->assertSee('New player');

    $statuses = collect($component->get('rows'))->pluck('status')->all();
    expect($statuses)->toBe(['listed', 'existing', 'new', 'new']);

    $component->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('step', 'input')
        ->assertSet('rows', [])
        ->assertSet('text', '')
        ->assertDispatched('players-imported', function (string $event, array $params) use (&$token): bool {
            $token = $params['token'] ?? null;

            return is_string($token) && strlen($token) >= 40 && array_keys($params) === ['token'];
        });

    // The result itself stays on the server.
    expect(Cache::get(ImportPlayers::resultKey($session, $token)))->toBe(['listed' => 3, 'created' => 2, 'already_listed' => 1, 'errors' => 0, 'notes' => []]);

    $bea = Player::where('club_id', $club->id)->where('name', 'Bea')->firstOrFail();
    $cy = Player::where('club_id', $club->id)->where('name', 'Cy')->firstOrFail();
    expect(SessionListPlayer::where('play_session_id', $session->id)->count())->toBe(4)
        ->and($bea->stars_pending)->toBeFalse()
        ->and($cy->stars)->toBe(3)->and($cy->stars_pending)->toBeTrue();
});

test('an empty paste is refused with a message', function () {
    [$owner, $club, $session] = importWorld();

    importList($owner, $club, $session)
        ->set('text', "  \n  ")
        ->call('reviewPaste')
        ->assertHasErrors('paste')
        ->assertSeeHtml('data-test="import-paste-error"')
        ->assertSet('step', 'input');
});

test('rows show errors and warnings, and an edit re-matches the row', function () {
    [$owner, $club, $session] = importWorld();
    Player::factory()->for($club)->create(['name' => 'Miguel']);

    $component = importList($owner, $club, $session)
        ->set('text', "Zed | rating=9.9 | colour=blue\nZed")
        ->call('reviewPaste')
        ->assertSeeHtml('data-test="import-row-warning"')
        ->assertSeeHtml('data-test="import-row-message"');

    expect(collect($component->get('rows'))->pluck('status')->all())->toBe(['error', 'error']);

    // Fix the bad rating, and the first row becomes a new player; the second is now a repeated name.
    $component->set('rows.0.rating', '4.5');
    expect(collect($component->get('rows'))->pluck('status')->all())->toBe(['new', 'error']);

    // Rename the second row to an existing player: it matches that player.
    $component->set('rows.1.name', 'miguel');
    $rows = $component->get('rows');
    expect($rows[1]['status'])->toBe('existing')->and($rows[1]['player_name'])->toBe('Miguel');
    $component->assertSee('Add 2 players');
});

test('dropping a row updates the summary, and dropping them all goes back to the paste step', function () {
    [$owner, $club, $session] = importWorld();

    importList($owner, $club, $session)
        ->set('text', "Ana\nBea")
        ->call('reviewPaste')
        ->assertSee('2 new')
        ->call('dropRow', 0)
        ->assertSee('1 new')
        ->assertSee('Add 1 player')
        ->call('dropRow', 0)
        ->assertSet('step', 'input')
        ->assertSet('rows', [])
        ->assertSet('text', "Ana\nBea");
});

test('Back returns to the paste step and keeps the typed text and tab', function () {
    [$owner, $club, $session] = importWorld();

    importList($owner, $club, $session)
        ->set('tab', 'reclub')
        ->set('text', 'Ana')
        ->call('reviewPaste')
        ->call('back')
        ->assertSet('step', 'input')
        ->assertSet('text', 'Ana')
        ->assertSet('tab', 'reclub')
        ->assertSeeHtml('data-test="import-panel-paste"');
});

test('nothing to add disables the Add button and refuses a confirm', function () {
    [$owner, $club, $session] = importWorld();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana']);
    listPlayers($session, $ana);

    importList($owner, $club, $session)
        ->set('text', 'Ana')
        ->call('reviewPaste')
        ->assertSee('Add 0 players')
        ->assertSeeHtml('disabled')
        ->call('confirm')
        ->assertDispatched('players-imported');
});

test('a re-review that is refused restores the last reviewed rows instead of rendering unreviewed ones', function () {
    [$owner, $club, $session] = importWorld();

    $component = importList($owner, $club, $session)
        ->set('text', 'Ana
Bea')
        ->call('reviewPaste');
    $reviewed = $component->get('rows');

    $forged = array_fill(0, 250, ['name' => 'Zed', 'rating' => '', 'dupr_id' => '', 'source' => 'paste']);
    $component->set('rows', $forged)
        ->assertHasErrors('players')
        ->assertSet('rows', $reviewed)
        ->assertSeeHtml('data-test="import-confirm-error"');
});

test('a row with no status still renders as an error row', function () {
    [$owner, $club, $session] = importWorld();

    importList($owner, $club, $session)
        ->set('text', 'Ana')
        ->call('reviewPaste')
        ->set('rows', [['name' => 'Ana']])
        ->assertSeeHtml('data-status="new"');
});

test('a Reclub link loads the confirmed players for review', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/reclub-event.html'))]);
    [$owner, $club, $session] = importWorld();
    RateLimiter::clear("reclub:fetch:user:{$owner->id}");

    $component = importList($owner, $club, $session)
        ->set('url', 'https://reclub.co/m/synth1')
        ->call('loadReclub')
        ->assertHasNoErrors()
        ->assertSet('step', 'review')
        ->assertSee('Ana Reyes');

    expect($component->get('rows'))->toHaveCount(5)
        ->and($component->get('rows')[0]['source'])->toBe('reclub');

    $component->call('confirm')->assertHasNoErrors();

    expect(SessionListPlayer::where('play_session_id', $session->id)->where('source', 'reclub')->count())->toBe(5);
    Http::assertSentCount(1);
});

test('a bad Reclub link or an unreadable page shows an inline error and stays on the link step', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response('<html></html>')]);
    [$owner, $club, $session] = importWorld();
    RateLimiter::clear("reclub:fetch:user:{$owner->id}");

    importList($owner, $club, $session)
        ->set('url', 'https://example.com/m/x')
        ->call('loadReclub')
        ->assertHasErrors('reclub')
        ->assertSeeHtml('data-test="import-reclub-error"')
        ->assertSet('step', 'input')
        ->set('url', 'https://reclub.co/m/x')
        ->call('loadReclub')
        ->assertHasErrors('reclub')
        ->assertSee("Couldn't read players from that Reclub link.");
});

test('on the form the confirm hands the rows over and writes nothing', function () {
    [$owner, $club, $session] = importWorld();

    $component = importList($owner, $club, $session, 'form')
        ->set('text', "Ana\nBea")
        ->call('reviewPaste')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('import-rows');

    expect(Player::where('club_id', $club->id)->count())->toBe(0)
        ->and(SessionListPlayer::query()->count())->toBe(0)
        ->and($component->get('rows'))->toBe([]);
});

test('forged row outcomes are ignored: the server re-matches every row on confirm', function () {
    [$owner, $club, $session] = importWorld();
    $foreign = Player::factory()->for(Club::factory()->create())->create(['name' => 'Foreign Fran']);

    importList($owner, $club, $session)
        ->set('text', 'Zed')
        ->call('reviewPaste')
        ->set('rows.0.status', 'existing')
        ->set('rows.0.player_id', $foreign->id)
        ->call('confirm')
        ->assertHasNoErrors();

    expect(SessionListPlayer::where('player_id', $foreign->id)->exists())->toBeFalse()
        ->and(Player::where('club_id', $club->id)->where('name', 'Zed')->exists())->toBeTrue();
});

test('the locked properties cannot be changed from the browser', function () {
    [$owner, $club, $session] = importWorld();
    $other = Club::factory()->create();

    expect(fn () => importList($owner, $club, $session)->set('club', $other))->toThrow(Exception::class)
        ->and(fn () => importList($owner, $club, $session)->set('step', 'review'))->toThrow(Exception::class)
        ->and(fn () => importList($owner, $club, $session)->set('mode', 'form'))->toThrow(Exception::class);
});

test('the import is closed to outsiders and ended sessions', function () {
    [$owner, $club, $session] = importWorld();
    $stranger = User::factory()->create();
    $ended = PlaySession::factory()->for($club)->ended()->create();

    Livewire::actingAs($stranger)->test(ImportPlayers::class, ['club' => $club, 'session' => $session])->assertStatus(404);
    Livewire::actingAs($owner)->test(ImportPlayers::class, ['club' => $club, 'session' => $ended])->assertForbidden();
    Livewire::actingAs($owner)->test(ImportPlayers::class, ['club' => Club::factory()->create(), 'session' => $session])->assertNotFound();
});

test('a parent that does not pass also-listed leaves the reactive prop null, and the import still works', function () {
    [$owner, $club, $session] = importWorld();

    $component = importList($owner, $club, $session);

    // What Livewire does when the parent re-renders: the child is hydrated with the props the parent
    // passed. A parent that leaves out :also-listed passes nothing, and the reactive prop becomes null.
    SupportReactiveProps::$pendingChildParams[$component->id()] = ['club' => $club, 'session' => $session, 'mode' => 'board'];

    $component->set('text', 'Ana
Bea')
        ->call('reviewPaste')
        ->assertHasNoErrors()
        ->assertSet('step', 'review')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('players-imported');

    expect(SessionListPlayer::where('play_session_id', $session->id)->count())->toBe(2);
});

test('the board panel re-renders with the import child mounted and the import still works', function () {
    [$owner, $club, $session] = importWorld();

    // The panel mounts the child; a parent re-render must not disturb it.
    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('wire:name="sessions.import-players"')
        ->call('dismissResults')
        ->call('checkInAll')
        ->assertHasNoErrors()
        ->assertSeeHtml('wire:name="sessions.import-players"');

    importList($owner, $club, $session)
        ->set('text', 'Ana')
        ->call('reviewPaste')
        ->call('confirm')
        ->assertHasNoErrors();
});
