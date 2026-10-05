<?php

use App\Models\Club;
use App\Models\Player;
use App\Services\RosterImport\RosterImportRow;
use App\Services\RosterImportService;

test('a name match with a different DUPR id is an error and never overwrites', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->rated(3.5, 'AAA111')->create(['name' => 'Sam Same']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nsam same,BBB222,4.0\n");
    $row = rowsByLine($preview)[2];

    expect($row->action)->toBe(RosterImportRow::ERROR)
        ->and($row->messages[0])->toContain('different DUPR ID');

    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($result->errors)->toBe(1)
        ->and($player->fresh()->dupr_id)->toBe('AAA111')
        ->and($club->players()->count())->toBe(1);
});

test('a name-matched player without a DUPR id may gain one', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->create(['name' => 'Gina Gain', 'dupr_id' => null]);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\ngina gain,CCC333,\n");

    expect(rowsByLine($preview)[2]->action)->toBe(RosterImportRow::UPDATE);

    app(RosterImportService::class)->commit($club, $preview);

    expect($player->fresh()->dupr_id)->toBe('CCC333');
});

test('commit refuses to overwrite a DUPR id that appeared after the preview', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->create(['name' => 'Late Larry', 'dupr_id' => null]);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nLate Larry,DDD444,\n");
    $player->forceFill(['dupr_id' => 'EEE555'])->save();

    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($result->errors)->toBe(1)->and($player->fresh()->dupr_id)->toBe('EEE555');
});
