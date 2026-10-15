<?php

use App\Enums\SessionPlayerStatus;
use App\Livewire\Public\CheckIn;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
use App\Services\SessionListService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function checkinSession(string $state = 'live'): PlaySession
{
    $club = Club::factory()->create();

    return PlaySession::factory()->for($club)->{$state}()->create();
}

beforeEach(fn () => RateLimiter::clear('selfcheckin:search:127.0.0.1:1'));

test('a closed, ended or unknown token shows the friendly page', function () {
    $ended = checkinSession('ended');

    $this->get('/checkin/'.($ended->checkin_token ?? 'abc'))->assertNotFound()->assertSee('Check-in is closed');
    $this->get('/checkin/doesnotexist')->assertNotFound()->assertSee('Check-in is closed');
});

test('a live token renders the page', function () {
    $session = checkinSession();
    listPlayers($session, Player::factory()->for($session->club)->create());

    $this->get('/checkin/'.$session->checkin_token)->assertOk()->assertSee($session->name)->assertSee('Find your name');
});

test('a session with an empty list shows the ask-the-organizer state and no search or register', function () {
    $session = checkinSession();

    $this->get('/checkin/'.$session->checkin_token)->assertOk()
        ->assertSeeHtml('data-test="checkin-no-list"')
        ->assertSee('Ask the organizer to add you to the player list')
        ->assertDontSeeHtml('data-test="checkin-search"')
        ->assertDontSee("I'm new");
});

test('search is debounced, needs 2 chars, and shows the name as entered and checked-in state', function () {
    $session = checkinSession();
    $mia = Player::factory()->for($session->club)->create(['name' => 'Mia Fullname']);
    $in = Player::factory()->for($session->club)->create(['name' => 'Mick Already']);
    listPlayers($session, $mia);
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $in->id]);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->assertSeeHtml('wire:model.live.debounce.300ms="search"')
        ->set('search', 'M')
        ->assertSet('results', [])
        ->set('search', 'Mi')
        ->assertSee('Mia Fullname')
        ->assertSee('Mick Already')
        ->assertSee('Checked in');
});

test('tapping a result confirms then checks in and remembers me', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Tess Tapper']);
    listPlayers($session, $player);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Tess')
        ->call('select', $player->public_id)
        ->assertSee('Check in as Tess Tapper')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('me-selected', publicId: $session->public_id, playerId: $player->public_id)
        ->assertSee("You're checked in")
        ->assertSee('/c/'.$session->club->slug.'/s/'.$session->public_id);

    expect(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(1);
});

test('checking in twice says already checked in', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Dora Twice']);
    listPlayers($session, $player);

    foreach (range(1, 2) as $i) {
        $t = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
            ->set('search', 'Dora')->call('select', $player->public_id)->call('confirm');
    }

    $t->assertSee("You're already checked in");
    expect(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(1);
});

test('self-register is gone: no register actions, no new-player button', function () {
    $session = checkinSession();

    $page = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->assertDontSeeHtml('data-test="im-new"')
        ->assertDontSeeHtml('data-test="register-form"');

    expect(method_exists($page->instance(), 'register'))->toBeFalse()
        ->and(method_exists($page->instance(), 'startRegister'))->toBeFalse();
});

test('a player who is not on the list is not found and cannot be checked in', function () {
    $session = checkinSession();
    $listed = Player::factory()->for($session->club)->create(['name' => 'Lena Listed']);
    $unlisted = Player::factory()->for($session->club)->create(['name' => 'Lena Unlisted']);
    listPlayers($session, $listed);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Lena')
        ->assertSee('Lena Listed')
        ->assertDontSee('Lena Unlisted')
        ->call('select', $unlisted->public_id)
        ->assertSet('selectedId', null)
        ->assertHasErrors('player');

    expect(SessionPlayer::query()->count())->toBe(0);
});

test('an empty list tells the visitor to ask the organizer', function () {
    $session = checkinSession();
    Player::factory()->for($session->club)->create(['name' => 'Nobody Listed']);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Nobody')
        ->assertSee('Ask the organizer to add you to the player list');
});

test('the password field only shows when the session has one, and the password is passed to the service', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Pam Password']);
    listPlayers($session, $player);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Pam')->call('select', $player->public_id)
        ->assertDontSeeHtml('data-test="checkin-password"');

    app(SessionListService::class)->setPassword($session, 'Kitchen7');

    $page = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Pam')->call('select', $player->public_id)
        ->assertSeeHtml('data-test="checkin-password"')
        ->call('confirm')
        ->assertHasErrors('password')
        ->set('password', 'nope')
        ->call('confirm')
        ->assertHasErrors('password')
        ->assertSee("That password isn't right")
        ->set('password', '  kitchen7 ')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee("You're checked in");

    expect(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(1);
});

test('a regenerated token stops the form', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Late Larry']);
    listPlayers($session, $player);

    $page = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Late')->call('select', $player->public_id);

    $session->forceFill(['checkin_token' => PlaySession::newCheckinToken()])->save();

    $page->call('confirm')->assertHasErrors('session')->assertSee('Check-in is closed');
    expect(SessionPlayer::query()->where('status', SessionPlayerStatus::Waiting->value)->count())->toBe(0);
});

test('the throttle error is shown for search and for submits', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Throt Tle']);
    listPlayers($session, $player);
    $ip = request()->ip() ?? '127.0.0.1';

    foreach (range(1, 60) as $i) {
        RateLimiter::hit("selfcheckin:search:{$ip}:{$session->id}", 60);
    }
    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Throt')
        ->assertHasErrors('throttle')
        ->assertSeeHtml('data-test="checkin-error"');

    RateLimiter::clear("selfcheckin:search:{$ip}:{$session->id}");
    foreach (range(1, 10) as $i) {
        RateLimiter::hit("selfcheckin:submit:{$ip}:{$session->id}", 60);
    }
    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Throt')
        ->call('select', $player->public_id)
        ->call('confirm')
        ->assertHasErrors('throttle');
});

test('select ignores ids that are not in the results or in another club', function () {
    $session = checkinSession();
    $mine = Player::factory()->for($session->club)->create(['name' => 'Mine Player']);
    $other = Player::factory()->for(Club::factory()->create())->create(['name' => 'Mine Foreign']);
    listPlayers($session, $mine);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Mine')
        ->call('select', $other->public_id)
        ->assertSet('selectedId', null)
        ->call('select', 'bogus')
        ->assertSet('selectedId', null)
        ->call('select', $mine->public_id)
        ->assertSet('selectedId', $mine->public_id);
});

test('token, results and selectedId are locked', function (string $property) {
    $session = checkinSession();

    expect(fn () => Livewire::test(CheckIn::class, ['token' => $session->checkin_token])->set($property, $property === 'results' ? [] : 'x'))
        ->toThrow(Exception::class, 'Cannot update locked property');
})->with(['token', 'results', 'selectedId']);

test('an error from the confirm step (already in another live session) is shown', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Busy Bee']);
    listPlayers($session, $player);
    $other = PlaySession::factory()->for($session->club)->live()->create();
    app(CheckInService::class)->checkIn($other, $player);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Busy')
        ->call('select', $player->public_id)
        ->call('confirm')
        ->assertHasErrors('player')
        ->assertSeeHtml('data-test="checkin-error"')
        ->assertSeeHtml('data-test="checkin-confirm"');
});

test('a search with no match says to ask the organizer, and there is no self-register', function () {
    $session = checkinSession();
    $listed = Player::factory()->for($session->club)->create(['name' => 'Lena Listed']);
    listPlayers($session, $listed);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Zzz')
        ->assertSeeHtml('data-test="checkin-no-match"')
        ->assertSee('Not on the list? Ask the organizer.')
        ->assertDontSee("I'm new")
        ->assertDontSeeHtml('data-test="register-form"');
});

test('the password step is part of the confirm form: wrong shows the message inline, right checks in', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Pam Password']);
    listPlayers($session, $player);
    app(SessionListService::class)->setPassword($session, 'Kitchen7');

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Pam')
        ->call('select', $player->public_id)
        ->assertSeeHtml('wire:submit="confirm"')
        ->assertSeeHtml('data-test="checkin-password"')
        ->assertSee('Check in as Pam Password?')
        ->set('password', 'wrong')
        ->call('confirm')
        ->assertSeeHtml('data-test="error-password"')
        ->assertSee("That password isn't right")
        ->assertSeeHtml('data-test="checkin-confirm"')
        ->call('cancelSelect')
        ->assertSet('password', '')
        ->assertDontSeeHtml('data-test="error-password"');
});

test('without a password the confirm step has no password field and checks in', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Nina NoPass']);
    listPlayers($session, $player);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Nina')
        ->call('select', $player->public_id)
        ->assertDontSeeHtml('data-test="checkin-password"')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee("You're checked in");
});
