<?php

use App\Enums\Gender;
use App\Enums\RotationMode;
use App\Livewire\Public\Queue;
use App\Livewire\Public\Tv;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\UpNext;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use Livewire\Livewire;

/** A live mixed session; $genders like 'MMWWN' (M man, W woman, N none), checked in left to right. */
function uiMixedBoard(string $genders, array $attrs = []): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create(['rotation_mode' => RotationMode::Mixed, ...$attrs]);
    $players = [];
    foreach (str_split($genders) as $g) {
        $player = Player::factory()->for($club)->manual(3)->create(['gender' => match ($g) {
            'M' => Gender::Man,
            'W' => Gender::Woman,
            default => null,
        }]);
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return [$session->fresh(), $players];
}

function uiGenderedPlayerOf(GameMatch $match, string $gender): Player
{
    return Player::findOrFail($match->matchPlayers()->whereHas('player', fn ($q) => $q->where('gender', $gender))->first()->player_id);
}

function mixedOwner($session): User
{
    $owner = User::factory()->create();
    $session->club->users()->attach($owner, ['role' => 'owner']);

    return $owner;
}

test('the session form explains the mixed mode', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    Livewire::actingAs($owner)->test(Form::class, ['club' => $club])
        ->assertSee('Mixed doubles')
        ->set('rotation_mode', 'mixed')
        ->assertSee('Every team is 1 man + 1 woman. Up Next waits until 2 of each are free.');
});

test('the waiting list in mixed mode shows the label, markers, the no-gender flag and the banner', function () {
    [$session, $players] = uiMixedBoard('MMWWMWNN');
    $owner = mixedOwner($session);

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->assertSeeHtml('data-test="mode-label"')
        ->assertSee('Mixed doubles')
        ->assertSeeHtml('data-gender="man"')
        ->assertSeeHtml('data-gender="woman"')
        ->assertSeeHtml('data-test="needs-gender"')
        ->assertSee('2 waiting players have no gender')
        ->assertSee('not placed');
});

test('quick set M or W refills and clears the flag and the banner', function () {
    [$session, $players] = uiMixedBoard('MMWWN');
    $owner = mixedOwner($session);
    $late = $players[4];

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->assertSee('1 waiting player has no gender')
        ->call('setGender', $late->id, 'woman')
        ->assertDontSeeHtml('data-test="unplaceable-banner"')
        ->assertDontSeeHtml('data-test="needs-gender"');

    expect($late->fresh()->gender)->toBe(Gender::Woman);
});

test('quick set rejects a player from another club', function () {
    [$session] = uiMixedBoard('MMWWN');
    $owner = mixedOwner($session);
    $foreign = Player::factory()->create();

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->call('setGender', $foreign->id, 'man')
        ->assertHasErrors('player');

    expect($foreign->fresh()->gender)->toBeNull();
});

test('the swap panel shows the same-gender error', function () {
    [$session, $players] = uiMixedBoard('MMWWMWN');
    $owner = mixedOwner($session);
    $match = stagedOf($session)[0];
    $out = uiGenderedPlayerOf($match, 'man');

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->call('openPanel', 'swap', $match->id)
        ->set('outPlayerId', (string) $out->id)
        ->set('inPlayerId', (string) $players[5]->id)
        ->assertSee('(W)')
        ->call('swap')
        ->assertHasErrors('player')
        ->assertSee('swap in a player of the same gender as the player leaving');
});

test('up next shows gender markers in mixed mode', function () {
    [$session] = uiMixedBoard('MMWWMW');
    $owner = mixedOwner($session);

    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->assertSeeHtml('data-gender="man"')
        ->assertSeeHtml('data-gender="woman"');
});

test('courts render in mixed mode', function () {
    [$session] = uiMixedBoard('MMWWMWMW', ['courts' => 1, 'auto_fill' => true]);
    $owner = mixedOwner($session);

    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertOk()
        ->assertSeeHtml('data-test="gender-marker"');
});

test('a balanced session shows no markers, labels or flags', function () {
    [$session] = board(7);
    $owner = mixedOwner($session);
    Player::query()->update(['gender' => Gender::Man]);

    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="mode-label"')
        ->assertDontSeeHtml('data-test="gender-marker"')
        ->assertDontSeeHtml('data-test="needs-gender"')
        ->assertDontSeeHtml('data-test="unplaceable-banner"')
        ->assertDontSee('not placed');
    Livewire::actingAs($owner)->test(UpNext::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="gender-marker"');
    Livewire::actingAs($owner)->test(Courts::class, ['session' => $session])
        ->assertDontSeeHtml('data-test="gender-marker"');
});

test('the tv and public queue show the mode label and no gender', function () {
    [$session] = uiMixedBoard('MMWWMWNN');

    foreach ([Tv::class, Queue::class] as $page) {
        $params = $page === Tv::class ? ['club' => $session->club, 'tvId' => $session->tv_id] : ['club' => $session->club, 'publicId' => $session->public_id];
        $html = Livewire::test($page, $params)->assertSee('Mixed doubles')->html();
        expect($html)->not->toContain('data-gender')->not->toContain('gender-marker')->not->toContain('No gender');
    }
});

test('balanced tv and queue have no mode label', function () {
    [$session] = board(6);

    Livewire::test(Tv::class, ['club' => $session->club, 'tvId' => $session->tv_id])->assertDontSee('Mixed doubles');
    Livewire::test(Queue::class, ['club' => $session->club, 'publicId' => $session->public_id])->assertDontSee('Mixed doubles');
});
