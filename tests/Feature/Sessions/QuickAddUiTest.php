<?php

use App\Enums\SessionPlayerStatus;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\Form;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use Livewire\Livewire;

/** @return array{0: User, 1: Club} */
function quickClub(): array
{
    $owner = User::factory()->create();

    return [$owner, Club::factory()->withOwner($owner)->create()];
}

test('the panel offers quick-add only for unknown names', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->create();
    Player::factory()->for($club)->create(['name' => 'Ana Lopez']);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->set('search', 'Zed')
        ->assertSeeHtml('data-test="quick-add-row"')
        ->assertSee('Add “Zed” as a new player')
        ->set('search', 'ana lopez')
        ->assertDontSeeHtml('data-test="quick-add-row"')
        ->set('search', 'Z')
        ->assertDontSeeHtml('data-test="quick-add-row"');
});

test('quick-add on the panel works for a draft and a live session', function () {
    [$owner, $club] = quickClub();
    foreach ([PlaySession::factory()->for($club)->create(), PlaySession::factory()->for($club)->live()->create()] as $i => $session) {
        Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
            ->set('search', "Newbie {$i}")
            ->call('openQuickAdd')
            ->set('quick.stars', '3')
            ->call('saveQuick')
            ->assertHasNoErrors();

        $player = Player::where('club_id', $club->id)->where('name', "Newbie {$i}")->firstOrFail();
        expect(SessionPlayer::where('play_session_id', $session->id)->where('player_id', $player->id)->exists())->toBeTrue();
    }
});

test('the panel shows a note for a deactivated name', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->live()->create();
    Player::factory()->for($club)->inactive()->create(['name' => 'Old Timer']);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->set('search', 'old timer')
        ->assertSeeHtml('data-test="deactivated-note"')
        ->assertSee('Old Timer is deactivated. Reactivate them on the Players page.')
        ->assertDontSeeHtml('data-test="quick-add-row"');
});

test('the panel shows inline validation errors', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->live()->create();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->set('search', 'Bad Input')
        ->call('openQuickAdd')
        ->set('quick.stars', '')
        ->set('quick.dupr_id', 'nope!')
        ->call('saveQuick')
        ->assertHasErrors(['quick.stars', 'quick.dupr_id'])
        ->assertSee('data-test="quick-add-form"', false);

    expect(Player::where('name', 'Bad Input')->exists())->toBeFalse();
});

test('the form creates a session with picks and new players, all checked in', function () {
    [$owner, $club] = quickClub();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Ana')
        ->assertSee('Ana Lopez')
        ->call('pick', $ana->id)
        ->assertSet('pickedIds', [$ana->id])
        ->set('search', 'Brand New')
        ->call('openQuickAdd')
        ->set('quick.stars', '5')
        ->call('addQuick')
        ->assertHasNoErrors()
        ->assertSee('To check in (2)')
        ->assertSee('new');

    expect(Player::where('name', 'Brand New')->exists())->toBeFalse();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Ana')->call('pick', $ana->id)
        ->set('search', 'Brand New')->call('openQuickAdd')->set('quick.stars', '5')->call('addQuick')
        ->call('save')
        ->assertHasNoErrors();

    $session = PlaySession::where('club_id', $club->id)->firstOrFail();
    $new = Player::where('name', 'Brand New')->firstOrFail();
    expect(SessionPlayer::where('play_session_id', $session->id)->pluck('player_id')->all())->toContain($ana->id, $new->id);
});

test('the edit form adds players to a live session and hides already checked in ones', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->live()->create();
    $in = Player::factory()->for($club)->create(['name' => 'Ana In']);
    $picked = Player::factory()->for($club)->create(['name' => 'Ana Picked']);
    $free = Player::factory()->for($club)->create(['name' => 'Ana Free']);
    app(CheckInService::class)->checkIn($session, $in);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('data-test="already-checked-in"')
        ->assertSee('Already checked in (1)')
        ->call('pick', $picked->id)
        ->set('search', 'Ana')
        ->assertSeeHtml('wire:key="result-'.$free->id.'"')
        ->assertDontSeeHtml('wire:key="result-'.$in->id.'"')
        ->assertDontSeeHtml('wire:key="result-'.$picked->id.'"')
        ->call('save')
        ->assertHasNoErrors();

    $entry = SessionPlayer::where('play_session_id', $session->id)->where('player_id', $picked->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->status)->not->toBe(SessionPlayerStatus::Left);
});

test('a foreign club player id is rejected and nothing is saved', function () {
    [$owner, $club] = quickClub();
    $foreign = Player::factory()->for(Club::factory()->create())->create(['name' => 'Foreign Fran']);

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('name', 'Should Not Exist')
        ->call('pick', $foreign->id)
        ->assertHasErrors(['players'])
        ->assertSet('pickedIds', []);

    expect(fn () => Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('pickedIds', [$foreign->id]))->toThrow(Exception::class, 'Cannot update locked property');

    expect(PlaySession::where('name', 'Should Not Exist')->exists())->toBeFalse()
        ->and(SessionPlayer::where('player_id', $foreign->id)->exists())->toBeFalse();
});

test('a duplicate pending name is rejected inline', function () {
    [$owner, $club] = quickClub();
    Player::factory()->for($club)->create(['name' => 'Taken Tim']);

    $test = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Pat Pending')->call('openQuickAdd')->set('quick.stars', '3')->call('addQuick')
        ->assertHasNoErrors()
        ->set('search', 'Pat Pending')->call('openQuickAdd')->set('quick.stars', '3')
        ->set('quick.name', 'pat PENDING')
        ->call('addQuick')
        ->assertHasErrors(['quick.name'])
        ->set('quick.name', 'taken tim')
        ->call('addQuick')
        ->assertHasErrors(['quick.name']);

    expect($test->get('newPlayers'))->toHaveCount(1);
});

test('the players section is hidden when the session has ended', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->ended()->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->assertDontSeeHtml('data-test="players-section"');

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSeeHtml('data-test="players-section"');
});

test('pending player errors from save show on the chip', function () {
    [$owner, $club] = quickClub();

    $test = Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->set('search', 'Dupe Dan')->call('openQuickAdd')->set('quick.stars', '3')->call('addQuick');

    Player::factory()->for($club)->create(['name' => 'Dupe Dan']);

    $test->call('save')
        ->assertHasErrors(['new_players.0.name'])
        ->assertSeeHtml('data-test="chip-error"');

    expect(PlaySession::where('club_id', $club->id)->exists())->toBeFalse();
});

test('outsiders cannot use the form or panel actions', function (string $who) {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->live()->create();
    $player = Player::factory()->for($club)->create(['name' => 'Ana Lopez']);
    $intruder = $who === 'member of another club'
        ? Club::factory()->withOwner(User::factory()->create())->create()->users()->firstOrFail()
        : User::factory()->create();

    // A failed Livewire call leaves the test component unusable, so each action gets a fresh one.
    $attempts = [
        fn () => Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session]),
        fn () => Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])->set('quick.name', 'Zed')->set('quick.stars', '3'),
        fn () => Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])->set('quick.name', 'Zed')->set('quick.stars', '3'),
        fn () => Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])->set('quick.name', 'Zed')->set('quick.stars', '3'),
    ];
    $actions = [['pick', [$player->id]], ['addQuick', []], ['save', []], ['saveQuick', []]];

    foreach ($actions as $i => [$method, $args]) {
        $component = $attempts[$i]();
        test()->actingAs($intruder);
        $component->call($method, ...$args)->assertStatus(404);
    }

    expect(Player::where('name', 'Zed')->exists())->toBeFalse()
        ->and(SessionPlayer::where('play_session_id', $session->id)->exists())->toBeFalse();
})->with(['non-member', 'member of another club']);

test('pick and addQuick are forbidden on an ended session', function () {
    [$owner, $club] = quickClub();
    $session = PlaySession::factory()->for($club)->ended()->create();
    $player = Player::factory()->for($club)->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->call('pick', $player->id)->assertForbidden();
    Livewire::actingAs($owner)->test(Form::class, ['club' => $club, 'session' => $session])
        ->set('quick.name', 'Zed')->set('quick.stars', '3')->call('addQuick')->assertForbidden();
});
