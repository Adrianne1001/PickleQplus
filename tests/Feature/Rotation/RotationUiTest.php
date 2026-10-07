<?php

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Weights;
use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Livewire\Public\CheckIn;
use App\Livewire\Sessions\Form;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\Rotation\BalancedStrategy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(fn () => RateLimiter::clear('x'));

function uiClub(): array
{
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    return [$owner, $club];
}

// --- session form ---

test('the session form offers only the enabled modes and saves balanced by default', function () {
    [$owner, $club] = uiClub();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSet('rotation_mode', 'balanced')
        ->assertSeeHtml('data-test="rotation-mode"')
        ->assertSee('Balanced')
        ->assertDontSee('Winners stay')
        ->call('save')
        ->assertHasNoErrors();

    expect($club->playSessions()->firstOrFail()->rotation_mode)->toBe(RotationMode::Balanced);
});

test('a draft session saves a newly enabled mode without confirmation', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    [$owner, $club] = uiClub();
    $session = PlaySession::factory()->for($club)->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSee('Mixed doubles')
        ->set('rotation_mode', 'mixed')
        ->call('save')
        ->assertSet('confirmingMode', false)
        ->assertHasNoErrors();

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed);
});

test('the form rejects a mode that is not enabled', function () {
    [$owner, $club] = uiClub();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('rotation_mode', 'winners_stay')
        ->call('save')
        ->assertHasErrors('rotation_mode');
});

test('switching the mode on a live session asks for confirmation, then saves end to end', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    app()->bind('rotation.strategy.mixed', fn () => new BalancedStrategy(new BalancedRotationEngine(Weights::fromConfig((array) config('pickleq.rotation')))));
    [$session] = board(8, ['courts' => 1, 'up_next_count' => 1, 'auto_fill' => true]);
    $owner = User::factory()->create();
    $session->club->users()->attach($owner, ['role' => 'owner']);
    $playing = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->firstOrFail();
    $stagedBefore = stagedOf($session)->first();

    // save() alone only opens the confirmation, however the client calls it.
    $page = Livewire::actingAs($owner)->test(Form::class, ['club' => $session->club, 'session' => $session])
        ->set('rotation_mode', 'mixed')
        ->call('save')
        ->assertSet('confirmingMode', true)
        ->assertNoRedirect()
        ->assertSee('Up Next matches will be cleared, and matches being played carry on.');
    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Balanced)
        ->and($stagedBefore->fresh()->status)->toBe(MatchStatus::Staged);

    $page->call('save', true)->assertSet('confirmingMode', true);
    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Balanced);

    $page->call('cancelModeChange')->assertSet('confirmingMode', false);
    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Balanced);

    $page->call('save')->call('confirmModeChange')->assertHasNoErrors()->assertRedirect();

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed)
        ->and($stagedBefore->fresh()->status)->toBe(MatchStatus::Void)
        ->and($playing->fresh()->status)->toBe(MatchStatus::Playing);
});

test('saving a live session without changing the mode does not ask', function () {
    [$owner, $club] = uiClub();
    $session = PlaySession::factory()->live()->for($club)->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertSet('confirmingMode', false)
        ->assertRedirect();
});

// --- player form and profile ---

test('the player form saves, shows and clears gender', function () {
    [$owner, $club] = uiClub();

    $page = Livewire::actingAs($owner)->test('pages::clubs.players', ['club' => $club])
        ->call('startAdd')
        ->assertSeeHtml('data-test="gender-input"')
        ->set('name', 'Gina Gender')
        ->set('stars', '3')
        ->set('gender', 'woman')
        ->call('save')
        ->assertHasNoErrors();

    $player = Player::query()->where('name', 'Gina Gender')->firstOrFail();
    expect($player->gender)->toBe(Gender::Woman);
    $page->assertSeeHtml('data-test="player-gender"')->assertSee('Woman');

    $page->call('startEdit', $player->id)->assertSet('gender', 'woman')
        ->set('gender', '')
        ->call('save')
        ->assertHasNoErrors();
    expect($player->fresh()->gender)->toBeNull();
});

test('an invalid gender fails validation on the player form', function () {
    [$owner, $club] = uiClub();

    Livewire::actingAs($owner)->test('pages::clubs.players', ['club' => $club])
        ->call('startAdd')
        ->set('name', 'Bad Gender')
        ->set('stars', '3')
        ->set('gender', 'robot')
        ->call('save')
        ->assertHasErrors('gender');
});

test('the player profile shows gender', function () {
    [$owner, $club] = uiClub();
    $player = Player::factory()->for($club)->create(['gender' => 'man']);

    $this->actingAs($owner)->get(route('clubs.players.show', [$club, $player]))
        ->assertOk()->assertSeeHtml('data-test="player-gender"')->assertSee('Man');
});

// --- roster import ---

test('the import preview shows a gender column', function () {
    [$owner, $club] = uiClub();
    $csv = "name,dupr_id,dupr_rating,gender\nAna Lopez,,,F\nBen Cruz,,,male\nCy Dee,,,\n";

    Livewire::actingAs($owner)->test('pages::clubs.players', ['club' => $club])
        ->call('startImport')
        ->set('importFile', UploadedFile::fake()->createWithContent('r.csv', $csv))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="import-gender"')
        ->assertSee('Woman')
        ->assertSee('Man');
});

// --- public check-in ---

test('self-register stores the optional gender', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->call('startRegister')
        ->assertSeeHtml('data-test="register-gender"')
        ->set('regName', 'New Nora')
        ->set('regNickname', 'Nora')
        ->set('regStars', '3')
        ->set('regGender', 'woman')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="checkin-done"');

    expect(Player::query()->where('name', 'New Nora')->firstOrFail()->gender)->toBe(Gender::Woman);
});

test('self-register works without a gender and rejects an invalid one', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->call('startRegister')
        ->set('regName', 'Bad Bob')
        ->set('regNickname', 'Bobby')
        ->set('regStars', '3')
        ->set('regGender', 'robot')
        ->call('register')
        ->assertHasErrors('gender')
        ->set('regGender', '')
        ->call('register')
        ->assertHasNoErrors();

    expect(Player::query()->where('name', 'Bad Bob')->firstOrFail()->gender)->toBeNull();
});

test('check-in offers gender only when the player has none and stores it', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $none = Player::factory()->for($session->club)->create(['name' => 'Gina Nogender', 'gender' => null]);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Gina')
        ->call('select', $none->public_id)
        ->assertSet('needsGender', true)
        ->assertSeeHtml('data-test="checkin-gender"')
        ->set('gender', 'woman')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($none->fresh()->gender)->toBe(Gender::Woman);
});

test('check-in never offers or overwrites an existing gender', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $set = Player::factory()->for($session->club)->create(['name' => 'Manny Set', 'gender' => 'man']);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Manny')
        ->assertDontSee('Woman')
        ->call('select', $set->public_id)
        ->assertSet('needsGender', false)
        ->assertDontSeeHtml('data-test="checkin-gender"')
        ->set('gender', 'woman')
        ->call('confirm');

    expect($set->fresh()->gender)->toBe(Gender::Man);
});
