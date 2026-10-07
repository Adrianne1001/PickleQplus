<?php

use App\Enums\Gender;
use App\Enums\RotationMode;
use App\Livewire\Inputs\PlayerInput;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\PlayerService;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImportService;
use App\Services\SelfCheckInService;
use Illuminate\Validation\ValidationException;

test('gender parsing accepts the documented words in any case', function () {
    foreach (['man', 'MAN', ' M ', 'male', 'Male'] as $v) {
        expect(Gender::tryParse($v))->toBe(Gender::Man);
    }
    foreach (['woman', 'W', 'female', 'FEMALE', 'f', 'Woman'] as $v) {
        expect(Gender::tryParse($v))->toBe(Gender::Woman);
    }
    foreach ([null, '', '  ', 'x', 'other', 5] as $v) {
        expect(Gender::tryParse($v))->toBeNull();
    }
});

test('staff create and update accept an optional gender', function () {
    $club = Club::factory()->create();
    $svc = app(PlayerService::class);

    $none = $svc->create($club, ['name' => 'Ana', 'stars' => 3]);
    $player = $svc->create($club, ['name' => 'Bo', 'stars' => 3, 'gender' => 'woman']);

    expect($none->fresh()->gender)->toBeNull()
        ->and($player->fresh()->gender)->toBe(Gender::Woman);

    // Absent key keeps it, explicit value overwrites, explicit null clears.
    $svc->update($player, ['name' => 'Bo B']);
    expect($player->fresh()->gender)->toBe(Gender::Woman);
    $svc->update($player, ['gender' => Gender::Man]);
    expect($player->fresh()->gender)->toBe(Gender::Man);
    $svc->update($player, ['gender' => null]);
    expect($player->fresh()->gender)->toBeNull();

    expect(fn () => $svc->update($player, ['gender' => 'robot']))->toThrow(ValidationException::class);
});

test('the player form validation rejects an invalid gender', function () {
    $club = Club::factory()->create();
    $input = new PlayerInput;

    expect($input->validate($club, null, 'Ana', '', '', 'manual', '3', null, 'woman')['gender'])->toBe('woman')
        ->and($input->validate($club, null, 'Ana', '', '', 'manual', '3', null, '')['gender'])->toBeNull()
        ->and($input->validate($club, null, 'Ana', '', '', 'manual', '3'))->not->toHaveKey('gender');
    expect(fn () => $input->validate($club, null, 'Ana', '', '', 'manual', '3', null, 'robot'))->toThrow(ValidationException::class);
});

test('roster import parses the gender column and exposes it in the preview', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "name,gender\nA,Man\nB,w\nC,FEMALE\nD,\nE,m\n");

    expect(array_map(fn ($r) => $r->gender, $preview->rows))->toBe(['man', 'woman', 'woman', null, 'man'])
        ->and($preview->rows[0]->toArray()['gender'])->toBe('man')
        ->and($preview->rows[0]->data)->toBe(['name' => 'A', 'dupr_id' => null, 'dupr_rating' => null]);

    app(RosterImportService::class)->commit($club, $preview);
    expect(Player::query()->where('name', 'A')->first()->gender)->toBe(Gender::Man)
        ->and(Player::query()->where('name', 'B')->first()->gender)->toBe(Gender::Woman)
        ->and(Player::query()->where('name', 'D')->first()->gender)->toBeNull();
});

test('an invalid gender makes the row an error and nothing is created for it', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "name,gender\nA,robot\nB,man\n");

    expect($preview->rows[0]->action)->toBe('error')
        ->and($preview->rows[0]->messages[0])->toContain('robot')
        ->and($preview->rows[1]->action)->toBe('create');

    app(RosterImportService::class)->commit($club, $preview);
    expect(Player::query()->where('name', 'A')->exists())->toBeFalse();
});

test('import updates a gender, a blank cell never clears one, and an unchanged gender skips', function () {
    $club = Club::factory()->create();
    $ana = Player::factory()->for($club)->manual(3)->create(['name' => 'Ana', 'gender' => Gender::Woman]);

    $blank = importPreview($club, "name,gender\nAna,\n");
    expect($blank->rows[0]->action)->toBe('skip');

    $same = importPreview($club, "name,gender\nAna,f\n");
    expect($same->rows[0]->action)->toBe('skip');

    $change = importPreview($club, "name,gender\nAna,man\n");
    expect($change->rows[0]->action)->toBe('update');
    app(RosterImportService::class)->commit($club, $change);
    expect($ana->fresh()->gender)->toBe(Gender::Man);

    app(RosterImportService::class)->commit($club, $blank);
    expect($ana->fresh()->gender)->toBe(Gender::Man);
});

test('an import without a gender column leaves genders alone', function () {
    $club = Club::factory()->create();
    $ana = Player::factory()->for($club)->manual(3)->create(['name' => 'Ana', 'gender' => Gender::Woman]);

    $preview = importPreview($club, "name,dupr_rating\nAna,\nBo,\n");
    app(RosterImportService::class)->commit($club, $preview);

    expect($ana->fresh()->gender)->toBe(Gender::Woman);
});

test('a stored preview round-trips the gender', function () {
    $club = Club::factory()->create();
    $preview = importPreview($club, "name,gender\nA,man\n");

    $restored = RosterImportPreview::fromArray(json_decode((string) json_encode($preview->toArray()), true));

    expect($restored->rows[0]->gender)->toBe('man');
});

test('self-register stores an optional gender and rejects an invalid one', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $svc = app(SelfCheckInService::class);

    $svc->register($session, (string) $session->checkin_token, 'Ana One', 'Anna', null, 3, 'gx', 'W');
    $svc->register($session, (string) $session->checkin_token, 'Bo Two', 'Bobo', null, 3, 'gx');

    expect(Player::query()->where('nickname', 'Anna')->first()->gender)->toBe(Gender::Woman)
        ->and(Player::query()->where('nickname', 'Bobo')->first()->gender)->toBeNull();

    expect(fn () => $svc->register($session, (string) $session->checkin_token, 'Cy Three', 'Cyc', null, 3, 'gx', 'robot'))
        ->toThrow(ValidationException::class);
    expect(Player::query()->where('nickname', 'Cyc')->exists())->toBeFalse();
});

test('self check-in sets a missing gender but never overwrites an existing one', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $token = (string) $session->checkin_token;
    $none = Player::factory()->for($session->club)->manual(3)->create();
    $has = Player::factory()->for($session->club)->manual(3)->create(['gender' => Gender::Man]);
    $svc = app(SelfCheckInService::class);

    $svc->checkIn($session, $token, (string) $none->public_id, null, 'gx', 'female');
    $svc->checkIn($session, $token, (string) $has->public_id, null, 'gx', 'woman');

    expect($none->fresh()->gender)->toBe(Gender::Woman)
        ->and($has->fresh()->gender)->toBe(Gender::Man);

    // Already checked in: still no overwrite.
    $svc->checkIn($session, $token, (string) $none->public_id, null, 'gx', 'man');
    expect($none->fresh()->gender)->toBe(Gender::Woman);

    expect(fn () => $svc->checkIn($session, $token, (string) $has->public_id, null, 'gx', 'robot'))->toThrow(ValidationException::class);
});

test('self check-in with a stale token never touches the gender', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $player = Player::factory()->for($session->club)->manual(3)->create();

    expect(fn () => app(SelfCheckInService::class)->checkIn($session, 'wrong-token', (string) $player->public_id, null, 'gx', 'man'))
        ->toThrow(ValidationException::class);
    expect($player->fresh()->gender)->toBeNull();
});

test('self check-in cannot set the gender of a player from another club', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    $other = Player::factory()->for(Club::factory()->create())->manual(3)->create();

    expect(fn () => app(SelfCheckInService::class)->checkIn($session, (string) $session->checkin_token, (string) $other->public_id, null, 'gx', 'man'))
        ->toThrow(ValidationException::class);
    expect($other->fresh()->gender)->toBeNull();
});

test('search rows flag whether a player still needs a gender without exposing it', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    Player::factory()->for($session->club)->manual(3)->create(['name' => 'Zed None']);
    Player::factory()->for($session->club)->manual(3)->create(['name' => 'Zed Has', 'gender' => Gender::Man]);
    Player::factory()->for(Club::factory()->create())->manual(3)->create(['name' => 'Zed Other']);

    $rows = collect(app(SelfCheckInService::class)->search($session, (string) $session->checkin_token, 'Zed', 'gx-search'))->keyBy('name');

    expect($rows)->toHaveCount(2)
        ->and($rows['Zed None']['needs_gender'])->toBeTrue()
        ->and($rows['Zed Has']['needs_gender'])->toBeFalse()
        ->and($rows['Zed Has'])->not->toHaveKey('gender');
});

test('a new in-memory session defaults to balanced', function () {
    expect((new PlaySession)->rotation_mode)->toBe(RotationMode::Balanced)
        ->and(PlaySession::factory()->make()->rotation_mode)->toBe(RotationMode::Balanced);
});
