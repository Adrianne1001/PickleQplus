<?php

use App\Enums\SessionStatus;
use App\Livewire\Sessions\CheckInQr;
use App\Livewire\Sessions\Show;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Livewire\Livewire;

function qrBoard(SessionStatus $status = SessionStatus::Live): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->create(['status' => $status]);

    return [$owner, $club, $session];
}

test('the board listens on its own session channel', function () {
    [$owner, $club, $session] = qrBoard();

    $component = Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $session]);

    expect($component->instance()->getListeners())
        ->toHaveKey('echo:play-session.'.$session->public_id.',.session.updated', 'syncFromBroadcast');

    $component->call('syncFromBroadcast')->assertDispatched('session-changed')->assertOk();
});

test('the board polls as a fallback', function () {
    [$owner, $club, $session] = qrBoard();

    Livewire::actingAs($owner)->test(Show::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('wire:poll.30s.visible="syncFromBroadcast"');
});

test('the QR panel renders an svg and links for draft and live sessions', function (SessionStatus $status) {
    [$owner, $club, $session] = qrBoard($status);

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertSeeHtml('data-test="checkin-qr-svg"')
        ->assertSeeHtml('<svg xmlns')
        ->assertSee(url('/checkin/'.$session->checkin_token))
        ->assertSee(url('/c/'.$club->slug.'/s/'.$session->public_id))
        ->assertSee(url('/c/'.$club->slug.'/tv/'.$session->tv_id))
        ->assertSee('Regenerate QR');
})->with([SessionStatus::Draft, SessionStatus::Live]);

test('the QR panel shows no qr for ended sessions', function () {
    [$owner, , $session] = qrBoard(SessionStatus::Ended);

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="checkin-qr-svg"')
        ->assertDontSee('Regenerate QR')
        ->assertSee('has ended');
});

test('regenerating changes the check-in token', function () {
    [$owner, , $session] = qrBoard();
    $old = $session->checkin_token;

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->call('regenerate')
        ->assertHasNoErrors();

    expect($session->fresh()->checkin_token)->not->toBe($old)->not->toBeNull();
});

test('non-members cannot use the QR panel or open the board', function () {
    [, $club, $session] = qrBoard();
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)->test(CheckInQr::class, ['session' => $session])->assertNotFound();
    Livewire::actingAs($stranger)->test(Show::class, ['club' => $club, 'session' => $session])->assertNotFound();
});

test('resetting the TV link changes it, for members only', function () {
    [$owner, $club, $session] = qrBoard();
    $old = $session->tv_id;

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertSeeHtml('data-test="reset-tv-link-button"')
        ->assertSeeHtml('wire:confirm')
        ->call('resetTvLink');

    expect($session->fresh()->tv_id)->not->toBe($old);

    Livewire::actingAs(User::factory()->create())->test(CheckInQr::class, ['session' => $session])->assertNotFound();
});
