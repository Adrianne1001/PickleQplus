<?php

use App\Enums\SessionPlayerStatus;
use App\Livewire\Public\CheckIn;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
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

    $this->get('/checkin/'.$session->checkin_token)->assertOk()->assertSee($session->name)->assertSee('Find your name');
});

test('search is debounced, needs 2 chars, and shows the name as entered and checked-in state', function () {
    $session = checkinSession();
    $mia = Player::factory()->for($session->club)->create(['name' => 'Mia Fullname']);
    $in = Player::factory()->for($session->club)->create(['name' => 'Mick Already']);
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

    foreach (range(1, 2) as $i) {
        $t = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
            ->set('search', 'Dora')->call('select', $player->public_id)->call('confirm');
    }

    $t->assertSee("You're already checked in");
    expect(SessionPlayer::query()->where('player_id', $player->id)->count())->toBe(1);
});

test('registering creates a player, checks them in and lists the star levels', function () {
    $session = checkinSession();

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->call('startRegister')
        ->assertSee('New to pickleball')
        ->assertSee('Tournament level')
        ->set('regName', 'Rae Newbie')
        ->set('regStars', '3')
        ->call('register')
        ->assertHasNoErrors()
        ->assertDispatched('me-selected')
        ->assertSee("You're checked in");

    $player = Player::query()->where('name', 'Rae Newbie')->firstOrFail();
    expect($player->stars)->toBe(3)->and($player->self_registered_at)->not->toBeNull();
});

test('register shows validation errors inline', function () {
    $session = checkinSession();

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->call('startRegister')
        ->set('regName', 'Sam')
        ->set('regStars', '9')
        ->call('register')
        ->assertHasErrors('stars');
});

test('register rejects an existing name in a different case', function () {
    $session = checkinSession();
    Player::factory()->for($session->club)->create(['name' => 'Taken Tim']);

    Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->call('startRegister')
        ->assertSee('your name or a nickname')
        ->set('regName', 'tAKEN tIM')
        ->set('regStars', '3')
        ->call('register')
        ->assertHasErrors('name')
        ->assertSee('already on the roster');

    expect(Player::query()->where('club_id', $session->club_id)->count())->toBe(1);
});

test('a regenerated token stops the form', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Late Larry']);

    $page = Livewire::test(CheckIn::class, ['token' => $session->checkin_token])
        ->set('search', 'Late')->call('select', $player->public_id);

    $session->forceFill(['checkin_token' => PlaySession::newCheckinToken()])->save();

    $page->call('confirm')->assertHasErrors('session')->assertSee('Check-in is closed');
    expect(SessionPlayer::query()->where('status', SessionPlayerStatus::Waiting->value)->count())->toBe(0);
});

test('the throttle error is shown for search and for submits', function () {
    $session = checkinSession();
    $player = Player::factory()->for($session->club)->create(['name' => 'Throt Tle']);
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
