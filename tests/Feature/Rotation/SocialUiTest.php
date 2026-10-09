<?php

use App\Livewire\Public\Queue;
use App\Livewire\Public\Tv;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Livewire\Livewire;

test('the session form explains the social mix mode', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed', 'social']]);
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSee('Social mix')
        ->set('rotation_mode', 'social')
        ->assertSee('Rotates partners before repeating, then spreads opponents as fairly as possible.')
        ->assertSee('Ratings aren');
});

test('the public queue and TV show the Social mix label', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create(['rotation_mode' => 'social']);

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])
        ->assertSeeHtml('data-test="mode-label"')
        ->assertSee('Social mix');
    Livewire::test(Tv::class, ['club' => $club, 'tvId' => $session->tv_id])
        ->assertSeeHtml('data-test="mode-label"')
        ->assertSee('Social mix');
});

test('the landing page lists Social mix', function () {
    $this->get('/')->assertOk()->assertSee('Social mix');
});

test('the organizer waiting list shows a Social mix badge only for social sessions', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $social = PlaySession::factory()->for($club)->live()->create(['rotation_mode' => 'social']);
    $balanced = PlaySession::factory()->for($club)->live()->create(['rotation_mode' => 'balanced']);

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $social])
        ->assertSeeHtml('data-test="mode-label"')
        ->assertSee('Social mix');
    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $balanced])
        ->assertDontSeeHtml('data-test="mode-label"')
        ->assertDontSee('Social mix');
});
