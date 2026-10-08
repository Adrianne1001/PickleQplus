<?php

use App\Livewire\Sessions\CheckInPanel;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use Livewire\Livewire;

function removeBoard(): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->live()->create();

    return [$owner, $club, $session];
}

test('staff can remove a bogus check-in', function () {
    [$owner, $club, $session] = removeBoard();
    $player = Player::factory()->for($club)->create(['name' => 'Spam Sam']);
    app(CheckInService::class)->checkIn($session, $player);

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('data-test="remove-checkin-button"')
        ->assertSeeHtml('wire:confirm')
        ->call('removeCheckIn', $player->id)
        ->assertHasNoErrors();

    expect(SessionPlayer::query()->where('player_id', $player->id)->exists())->toBeFalse();
});

test('removing is blocked for a player in a match and the error is shown', function () {
    [$owner, $club, $session] = removeBoard();
    foreach (range(1, 4) as $i) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($club)->manual(3)->create());
    }
    $staged = SessionPlayer::query()->where('play_session_id', $session->id)->firstOrFail();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->call('removeCheckIn', $staged->player_id)
        ->assertHasErrors('player')
        ->assertSeeHtml('data-test="check-in-error"');

    expect(SessionPlayer::query()->whereKey($staged->id)->exists())->toBeTrue();
});

test('non-staff cannot remove', function () {
    [, $club, $session] = removeBoard();
    $player = Player::factory()->for($club)->create();
    app(CheckInService::class)->checkIn($session, $player);

    Livewire::actingAs(User::factory()->create())->test(CheckInPanel::class, ['session' => $session])->assertNotFound();
});

test('the new badge marks players self-registered in this session', function () {
    [$owner, $club, $session] = removeBoard();
    $new = Player::factory()->for($club)->create(['name' => 'Newbie Nora']);
    $new->forceFill(['self_registered_at' => now(), 'self_registered_session_id' => $session->id])->save();
    $old = Player::factory()->for($club)->create(['name' => 'Regular Rick']);
    app(CheckInService::class)->checkIn($session, $new);
    app(CheckInService::class)->checkIn($session, $old);

    $html = Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSee('Newbie Nora')->assertSee('Regular Rick')
        ->html();

    expect(substr_count($html, 'data-test="new-badge"'))->toBe(1);
});
