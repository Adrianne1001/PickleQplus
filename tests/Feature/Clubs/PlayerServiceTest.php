<?php

use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Services\PlayerService;
use Illuminate\Validation\ValidationException;

test('a duplicate DUPR id through the service is a validation error, not a 500', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['dupr_id' => 'ABC123']);

    try {
        // Not pre-normalized and not validated: the service must cope.
        app(PlayerService::class)->create($club, ['name' => 'Dup', 'dupr_id' => ' abc123 ', 'stars' => 2]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['dupr_id'][0])->toBe('Another player in this club already has this DUPR ID.');
    }

    expect($club->players()->count())->toBe(1);
});

test('updating a player to a DUPR id used by another player in the club fails', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['dupr_id' => 'ABC123']);
    $other = Player::factory()->for($club)->create(['dupr_id' => 'ZZZ999']);

    expect(fn () => app(PlayerService::class)->update($other, ['dupr_id' => 'abc123']))
        ->toThrow(ValidationException::class);

    expect($other->fresh()->dupr_id)->toBe('ZZZ999');
});

test('rating_source dupr without a rating ends up manual and needs stars', function () {
    $club = Club::factory()->create();
    $service = app(PlayerService::class);

    expect(fn () => $service->create($club, ['name' => 'No Rating', 'rating_source' => 'dupr']))
        ->toThrow(ValidationException::class);

    $player = $service->create($club, ['name' => 'No Rating', 'rating_source' => 'dupr', 'stars' => 3]);
    expect($player->rating_source)->toBe(RatingSource::Manual)->and($player->stars)->toBe(3);
});

test('stars sent for a dupr-sourced player are rejected', function () {
    $club = Club::factory()->create();
    $service = app(PlayerService::class);

    try {
        $service->create($club, ['name' => 'Rated', 'dupr_rating' => 4.0, 'stars' => 6]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['stars'][0])->toBe('Switch to manual to override stars.');
    }

    $player = $service->create($club, ['name' => 'Manual', 'dupr_rating' => 4.0, 'rating_source' => 'manual', 'stars' => 6]);
    expect($player->stars)->toBe(6);
});
