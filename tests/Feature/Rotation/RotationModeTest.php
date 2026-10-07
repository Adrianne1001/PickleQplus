<?php

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\Weights;
use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\PlaySessionService;
use App\Services\Rotation\BalancedStrategy;
use App\Services\Rotation\RotationStrategies;
use App\Services\Rotation\SkillCourtsStrategy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

test('the migration adds the columns with their defaults', function () {
    expect(Schema::hasColumns('play_sessions', ['rotation_mode', 'mode_settings']))->toBeTrue()
        ->and(Schema::hasColumn('players', 'gender'))->toBeTrue();

    $session = PlaySession::factory()->create()->fresh();
    $player = Player::factory()->create()->fresh();

    expect($session->rotation_mode)->toBe(RotationMode::Balanced)
        ->and($session->getRawOriginal('mode_settings'))->toBeNull()
        ->and($player->gender)->toBeNull();
});

test('mode settings read in a fixed key order with defaults', function () {
    $session = PlaySession::factory()->create();
    expect($session->fresh()->mode_settings)->toBe(['winners_stay_max_wins' => 2, 'skill_groups' => []]);

    config(['pickleq.rotation.winners_stay_max_wins' => 3]);
    expect($session->fresh()->mode_settings['winners_stay_max_wins'])->toBe(3);

    $session->mode_settings = ['skill_groups' => [], 'winners_stay_max_wins' => 4];
    $session->save();
    expect(array_keys($session->fresh()->mode_settings))->toBe(['winners_stay_max_wins', 'skill_groups'])
        ->and($session->fresh()->mode_settings['winners_stay_max_wins'])->toBe(4);
});

test('offers only the enabled modes', function () {
    $club = Club::factory()->create();
    $svc = app(PlaySessionService::class);

    expect($svc->create($club, ['rotation_mode' => 'balanced'])->rotation_mode)->toBe(RotationMode::Balanced);

    foreach (['winners_stay', 'king_of_court', 'nonsense'] as $mode) {
        expect(fn () => $svc->create($club, ['rotation_mode' => $mode]))->toThrow(ValidationException::class);
    }

    $session = $svc->create($club);
    expect(fn () => $svc->update($session, ['rotation_mode' => 'winners_stay']))->toThrow(ValidationException::class)
        ->and($session->fresh()->rotation_mode)->toBe(RotationMode::Balanced);
});

test('the allow-list is config driven', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    $session = PlaySession::factory()->create();

    app(PlaySessionService::class)->update($session, ['rotation_mode' => 'mixed']);

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed);
});

test('mode settings validate the winners stay max wins and keep skill groups stored but unused outside skill courts', function () {
    $session = PlaySession::factory()->create();
    $svc = app(PlaySessionService::class);

    $svc->update($session, ['mode_settings' => ['winners_stay_max_wins' => 4]]);
    expect($session->fresh()->mode_settings)->toBe(['winners_stay_max_wins' => 4, 'skill_groups' => []]);

    foreach ([0, 6] as $bad) {
        expect(fn () => $svc->update($session, ['mode_settings' => ['winners_stay_max_wins' => $bad]]))->toThrow(ValidationException::class);
    }

    // A balanced session ignores skill groups and keeps what is stored.
    $svc->update($session, ['mode_settings' => ['skill_groups' => [['from_court' => 1]]]]);
    expect($session->fresh()->mode_settings)->toBe(['winners_stay_max_wins' => 4, 'skill_groups' => []]);

    // Updating something else leaves the stored settings alone.
    $svc->update($session, ['name' => 'Renamed']);
    expect($session->fresh()->mode_settings['winners_stay_max_wins'])->toBe(4);
});

test('the strategy factory returns the balanced strategy and refuses modes that are not built', function () {
    expect(RotationStrategies::for(PlaySession::factory()->make(['rotation_mode' => RotationMode::Balanced])))->toBeInstanceOf(BalancedStrategy::class);

    $unbuilt = PlaySession::factory()->make(['rotation_mode' => RotationMode::WinnersStay]);
    expect(fn () => RotationStrategies::for($unbuilt))->toThrow(LogicException::class);

    expect(RotationStrategies::for(PlaySession::factory()->make(['rotation_mode' => RotationMode::SkillCourts])))->toBeInstanceOf(SkillCourtsStrategy::class);
});

test('a draft saves a mode change without touching matches', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    $session = PlaySession::factory()->create();
    Event::fake([PlaySessionChanged::class]);

    app(PlaySessionService::class)->update($session, ['rotation_mode' => 'mixed', 'mode_settings' => ['winners_stay_max_wins' => 3]]);

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed);
    Event::assertDispatched(PlaySessionChanged::class);
});

test('a mode change on a live session voids staged matches, keeps playing ones and refills', function () {
    config(['pickleq.rotation_modes_enabled' => ['balanced', 'mixed']]);
    app()->bind('rotation.strategy.mixed', fn () => new BalancedStrategy(new BalancedRotationEngine(Weights::fromConfig((array) config('pickleq.rotation')))));

    [$session] = board(8, ['courts' => 1, 'up_next_count' => 1, 'auto_fill' => true]);
    $playing = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->first();
    $stagedBefore = stagedOf($session);
    expect($playing)->not->toBeNull()->and($stagedBefore)->toHaveCount(1)
        ->and(app(PlaySessionService::class)->changeVoidsUpNext($session, ['rotation_mode' => 'mixed']))->toBeTrue();

    Event::fake([PlaySessionChanged::class]);
    app(PlaySessionService::class)->update($session, ['rotation_mode' => 'mixed']);

    expect($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed)
        ->and($stagedBefore[0]->fresh()->status)->toBe(MatchStatus::Void)
        ->and($playing->fresh()->status)->toBe(MatchStatus::Playing);

    $stagedAfter = stagedOf($session);
    expect($stagedAfter)->toHaveCount(1)->and($stagedAfter[0]->id)->not->toBe($stagedBefore[0]->id);
    Event::assertDispatched(PlaySessionChanged::class, fn ($e) => $e->playSessionId === $session->id);
});

test('a max wins change on a live session keeps the staged matches', function () {
    [$session] = board(4, ['courts' => 1, 'up_next_count' => 1]);
    $staged = stagedOf($session);

    expect(app(PlaySessionService::class)->changeVoidsUpNext($session, ['mode_settings' => ['winners_stay_max_wins' => 5]]))->toBeFalse();
    app(PlaySessionService::class)->update($session, ['mode_settings' => ['winners_stay_max_wins' => 5]]);

    expect($session->fresh()->mode_settings['winners_stay_max_wins'])->toBe(5)
        ->and($staged[0]->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a draft never voids, and a session keeps its mode even if it is no longer enabled', function () {
    $draft = PlaySession::factory()->create();
    expect(app(PlaySessionService::class)->changeVoidsUpNext($draft, ['rotation_mode' => 'mixed']))->toBeFalse();

    $session = PlaySession::factory()->create(['rotation_mode' => RotationMode::Mixed]);
    app(PlaySessionService::class)->update($session, ['name' => 'Still editable']);
    expect($session->fresh()->name)->toBe('Still editable')->and($session->fresh()->rotation_mode)->toBe(RotationMode::Mixed);
});

test('an update that does not change the mode keeps staged matches', function () {
    [$session] = board(4, ['courts' => 1, 'up_next_count' => 1]);
    $staged = stagedOf($session);
    expect($staged)->toHaveCount(1);

    app(PlaySessionService::class)->update($session, ['name' => 'Renamed', 'rotation_mode' => 'balanced']);

    expect($staged[0]->fresh()->status)->toBe(MatchStatus::Staged);
});

test('setGender is club scoped, locked, and may overwrite', function () {
    [$session, $players] = board(2);
    $svc = app(CheckInService::class);

    $svc->setGender($session, $players[0], 'woman');
    expect($players[0]->fresh()->gender)->toBe(Gender::Woman);

    $svc->setGender($session, $players[0], Gender::Man);
    expect($players[0]->fresh()->gender)->toBe(Gender::Man);

    $svc->setGender($session, $players[0], null);
    expect($players[0]->fresh()->gender)->toBeNull();

    expect(fn () => $svc->setGender($session, $players[0], 'robot'))->toThrow(ValidationException::class);

    $outsider = Player::factory()->for(Club::factory()->create())->create();
    expect(fn () => $svc->setGender($session, $outsider, 'man'))->toThrow(ValidationException::class)
        ->and($outsider->fresh()->gender)->toBeNull();

    $ended = PlaySession::factory()->for($session->club)->ended()->create();
    expect(fn () => $svc->setGender($ended, $players[0], 'man'))->toThrow(ValidationException::class);
});

test('setGender fires the session event', function () {
    [$session, $players] = board(1);
    Event::fake([PlaySessionChanged::class]);

    app(CheckInService::class)->setGender($session, $players[0], 'man');

    Event::assertDispatched(PlaySessionChanged::class, fn ($e) => $e->playSessionId === $session->id);
});
