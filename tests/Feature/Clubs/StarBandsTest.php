<?php

use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\ClubService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('updating bands recomputes dupr-sourced stars but not manual ones', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    $rated = Player::factory()->for($club)->rated(3.2)->create();      // 3 stars by default
    $manual = Player::factory()->for($club)->manual(5)->create(['dupr_rating' => '3.200']);
    $unrated = Player::factory()->for($club)->manual(2)->create();
    $otherClub = Club::factory()->create();
    $foreign = Player::factory()->for($otherClub)->rated(3.2)->create();

    expect($rated->stars)->toBe(3);

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->set('star_bands', ['3.0', '3.1', '3.2', '3.3', '3.4'])
        ->call('saveStarBands')
        ->assertHasNoErrors();

    expect($club->fresh()->star_bands)->toEqual([3.0, 3.1, 3.2, 3.3, 3.4])
        ->and($rated->fresh()->stars)->toBe(4)
        ->and($manual->fresh()->stars)->toBe(5)
        ->and($manual->fresh()->rating_source)->toBe(RatingSource::Manual)
        ->and($unrated->fresh()->stars)->toBe(2)
        ->and($foreign->fresh()->stars)->toBe(3);
});

test('invalid bands are rejected and change nothing', function (array $bands) {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $player = Player::factory()->for($club)->rated(3.2)->create();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->set('star_bands', $bands)
        ->call('saveStarBands')
        ->assertHasErrors();

    expect($club->fresh()->star_bands)->toEqual(config('pickleq.star_bands'))
        ->and($player->fresh()->stars)->toBe(3);
})->with([
    'too few' => [['2.5', '3', '3.5', '4']],
    'descending' => [['4.5', '4', '3.5', '3', '2.5']],
    'equal values' => [['2.5', '3', '3', '4', '4.5']],
    'out of range' => [['1.5', '3', '3.5', '4', '4.5']],
    'non numeric' => [['2.5', 'a', '3.5', '4', '4.5']],
]);

test('staff cannot edit star bands', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.settings', ['club' => $club])->assertForbidden();

    expect($club->fresh()->star_bands)->toEqual(config('pickleq.star_bands'));
});

test('the editor shows a live preview table matching the bands', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->assertSeeHtml('data-test="band-preview"')
        ->assertSee('below 2.50')
        ->assertSee('2.50 – 2.99')
        ->assertSee('4.00 – 4.49')
        ->assertSee('4.50 and up');

    $page->set('star_bands.0', '3.00')->assertSet('star_bands.0', '3.00');
    // 3.00 equals the next band, so the bands are invalid and the preview asks for valid input.
    $page->assertSee('Enter five ascending thresholds');

    $page->set('star_bands.0', '2.00')->assertSee('below 2.00')->assertSee('2.00 – 2.99');
});

test('the editor can reset to the default bands', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    Livewire::actingAs($owner)->test('pages::clubs.settings', ['club' => $club])
        ->set('star_bands', ['3.00', '3.10', '3.20', '3.30', '3.40'])
        ->call('resetStarBands')
        ->assertSet('star_bands', ['2.50', '3.00', '3.50', '4.00', '4.50']);
});

test('the service rejects invalid bands', function () {
    $club = Club::factory()->create();

    expect(fn () => app(ClubService::class)->updateStarBands($club, [1, 2, 3]))->toThrow(ValidationException::class);
});

test('new clubs get the default bands from config', function () {
    config(['pickleq.star_bands' => [2.0, 2.5, 3.0, 3.5, 4.0]]);
    $club = app(ClubService::class)->create(User::factory()->create(), ['name' => 'Cfg']);

    expect($club->star_bands)->toEqual([2.0, 2.5, 3.0, 3.5, 4.0]);
});
