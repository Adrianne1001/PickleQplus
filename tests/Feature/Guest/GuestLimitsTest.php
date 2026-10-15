<?php

use App\Enums\SessionPlayerStatus;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\Form;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\CheckInService;
use App\Services\PlayerListImportService;
use App\Services\PlayerService;
use App\Services\PlaySessionService;
use App\Services\SelfCheckInService;
use App\Services\SessionFormService;
use App\Services\SessionListService;
use App\Support\GuestOwnerContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function expectGuestLimit(Closure $fn, string $key): void
{
    try {
        $fn();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);

        return;
    }
    throw new RuntimeException('Expected the guest limit to reject this.');
}

/** @return list<Player> */
function guestPlayers(Club $club, int $n): array
{
    return Player::factory()->for($club)->manual(3)->count($n)->create()->all();
}

// --- one session ---

test('a guest club cannot get a second session, a normal club can', function () {
    [$club] = guestBoard();
    $normal = Club::factory()->create();
    PlaySession::factory()->for($normal)->create();

    expectGuestLimit(fn () => app(PlaySessionService::class)->create($club, ['name' => 'Second']), 'session');
    expect($club->playSessions()->count())->toBe(1);

    app(PlaySessionService::class)->create($normal, ['name' => 'Second']);
    expect($normal->playSessions()->count())->toBe(2);
});

test('the session form save refuses to create a second session in a guest club', function () {
    [$club] = guestBoard();

    expectGuestLimit(fn () => app(SessionFormService::class)->save($club, null, ['name' => 'Another']), 'session');
    expect($club->playSessions()->count())->toBe(1);
});

test('a guest club can start a session when it has none yet', function () {
    $club = Club::factory()->guest()->create();

    app(PlaySessionService::class)->create($club);

    expect($club->playSessions()->count())->toBe(1);
});

// --- courts ---

test('guest sessions are capped at 6 courts on create, update and skill group resize', function () {
    $club = Club::factory()->guest()->create();
    $service = app(PlaySessionService::class);

    expectGuestLimit(fn () => $service->create($club, ['courts' => 7]), 'courts');
    expect($club->playSessions()->count())->toBe(0);

    $session = $service->create($club, ['courts' => 6]);
    expect($session->courts)->toBe(6);

    expectGuestLimit(fn () => $service->update($session, ['courts' => 7]), 'courts');
    expectGuestLimit(fn () => $service->update($session, ['courts' => 7, 'rotation_mode' => 'skill_courts']), 'courts');
    expect($session->fresh()->courts)->toBe(6);

    $service->update($session, ['courts' => 2, 'rotation_mode' => 'skill_courts']);
    expectGuestLimit(fn () => $service->update($session->fresh(), ['courts' => 8]), 'courts');
});

test('the court cap error says why', function () {
    $club = Club::factory()->guest()->create();

    expect(fn () => app(PlaySessionService::class)->create($club, ['courts' => 9]))
        ->toThrow(ValidationException::class, 'A guest session can have at most 6 courts');
});

test('the session form rejects 7 courts for a guest club', function () {
    [$club, $session] = guestBoard();
    app(GuestOwnerContext::class)->set($club);

    Livewire::test(Form::class, ['club' => $club, 'session' => $session])
        ->set('courts', '7')->call('save')->assertHasErrors('courts');
    expect($session->fresh()->courts)->toBe(4);
});

test('normal clubs keep the 50 court cap', function () {
    $normal = Club::factory()->create();
    $service = app(PlaySessionService::class);

    expect($service->create($normal, ['courts' => 20])->courts)->toBe(20);
    expectGuestLimit(fn () => $service->create($normal, ['courts' => 51]), 'courts');
});

// --- checked in ---

test('the 41st checked-in player is rejected on every check-in path', function () {
    config(['pickleq.guest.max_checked_in' => 3]);
    [$club, $session] = guestBoard();
    $checkIns = app(CheckInService::class);
    foreach (guestPlayers($club, 3) as $p) {
        $checkIns->checkIn($session, $p);
    }
    [$extra, $extra2] = guestPlayers($club, 2);

    // Service and the list's Check In.
    expectGuestLimit(fn () => $checkIns->checkIn($session, $extra), 'player');
    $list = app(SessionListService::class);
    $list->add($session, [$extra->id, $extra2->id]);
    expectGuestLimit(fn () => $list->checkIn($session, $extra->id), 'player');
    // Check in all is best effort: the full session skips everyone with the reason.
    $all = $list->checkInAll($session);
    expect($all['checked_in'])->toBe(0)->and($all['skipped'])->toHaveCount(2)
        ->and($all['skipped'][0]['reason'])->toContain('at most 3 players checked in');
    // Self check-in (the player has to be listed).
    RateLimiter::clear('selfcheckin:submit:9.9.9.9:'.$session->id);
    expectGuestLimit(fn () => app(SelfCheckInService::class)->checkIn($session, (string) $session->checkin_token, (string) $extra->public_id, '9.9.9.9'), 'player');
    // The board component.
    app(GuestOwnerContext::class)->set($club);
    Livewire::test(CheckInPanel::class, ['session' => $session])->call('checkIn', $extra2->id)->assertHasErrors('player');

    expect(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(3);
});

test('left players free a slot, and a player returning from break needs none', function () {
    config(['pickleq.guest.max_checked_in' => 3]);
    [$club, $session] = guestBoard();
    $checkIns = app(CheckInService::class);
    [$a, $b, $c, $d] = guestPlayers($club, 4);
    foreach ([$a, $b, $c] as $p) {
        $checkIns->checkIn($session, $p);
    }

    $checkIns->goOnBreak($session, $a);
    $checkIns->checkIn($session, $a); // back from break: already counted
    expect(entryOf($session, $a)->status)->toBe(SessionPlayerStatus::Waiting);

    expectGuestLimit(fn () => $checkIns->checkIn($session, $d), 'player');

    $checkIns->checkOut($session, $b);
    $checkIns->checkIn($session, $d);
    // The player who left cannot come back while the session is full again.
    expectGuestLimit(fn () => $checkIns->checkIn($session, $b), 'player');
});

test('normal clubs have no checked-in cap', function () {
    config(['pickleq.guest.max_checked_in' => 2]);
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create();

    foreach (guestPlayers($club, 5) as $p) {
        app(CheckInService::class)->checkIn($session, $p);
    }

    expect(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(5);
});

// --- roster ---

test('the 61st player is rejected in the player service, quick add, form and import', function () {
    config(['pickleq.guest.max_roster' => 3]);
    [$club, $session] = guestBoard();
    $players = app(PlayerService::class);
    foreach (['A One', 'B Two', 'C Three'] as $name) {
        $players->create($club, ['name' => $name, 'stars' => 3]);
    }

    expectGuestLimit(fn () => $players->create($club, ['name' => 'D Four', 'stars' => 3]), 'name');
    expectGuestLimit(fn () => app(SessionListService::class)->addNewPlayer($session, ['name' => 'D Four', 'stars' => 3]), 'name');
    expectGuestLimit(fn () => app(SessionFormService::class)->save($club, $session, [], [], [['name' => 'D Four', 'stars' => 3]]), 'new_players.0.name');
    expectGuestLimit(fn () => app(PlayerListImportService::class)->confirm($session, [['name' => 'D Four', 'source' => 'paste']]), 'players');

    expect($club->players()->count())->toBe(3);
});

// --- listed ---

test('the 61st listed player is rejected on every list path, and normal clubs have no list cap', function () {
    config(['pickleq.guest.max_listed' => 3]);
    [$club, $session] = guestBoard();
    $list = app(SessionListService::class);
    [$a, $b, $c, $d] = guestPlayers($club, 4);
    $list->add($session, [$a->id, $b->id, $c->id]);

    expectGuestLimit(fn () => $list->add($session, [$d->id]), 'players');
    expectGuestLimit(fn () => $list->addNewPlayer($session, ['name' => 'List New', 'stars' => 3]), 'players');
    expectGuestLimit(fn () => app(SessionFormService::class)->save($club, $session, [], [$d->id]), 'players');
    expectGuestLimit(fn () => app(SessionFormService::class)->save($club, $session, [], [], [['name' => 'Form New', 'stars' => 3]]), 'players');
    expectGuestLimit(fn () => app(PlayerListImportService::class)->confirm($session, [['name' => 'Pasted New', 'source' => 'paste'], ['name' => $d->name, 'source' => 'paste']]), 'players');
    // Listing someone already listed needs no room.
    expect($list->add($session, [$a->id]))->toBe(0)
        ->and($club->players()->whereIn('name', ['List New', 'Form New', 'Pasted New'])->exists())->toBeFalse();

    $normal = Club::factory()->create();
    $open = PlaySession::factory()->for($normal)->create();
    $list->add($open, array_map(fn (Player $p): int => $p->id, guestPlayers($normal, 5)));
    expect($open->listPlayers()->count())->toBe(5);
});

test('editing a player in a full guest roster is fine, and normal clubs have no roster cap', function () {
    config(['pickleq.guest.max_roster' => 2]);
    $club = Club::factory()->guest()->create();
    $players = app(PlayerService::class);
    $first = $players->create($club, ['name' => 'A One', 'stars' => 3]);
    $players->create($club, ['name' => 'B Two', 'stars' => 3]);

    $players->update($first, ['name' => 'A Renamed']);
    expect($first->fresh()->name)->toBe('A Renamed');

    $normal = Club::factory()->create();
    foreach (['A', 'B', 'C', 'D'] as $name) {
        $players->create($normal, ['name' => $name.' Player', 'stars' => 3]);
    }
    expect($normal->players()->count())->toBe(4);
});
