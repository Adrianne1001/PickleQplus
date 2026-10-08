<?php

use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

test('a BOM, CRLF line endings and blank lines are handled', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "\xEF\xBB\xBFname,dupr_id,dupr_rating\r\nAna,8dplx8,4.25\r\n\r\nBo,,\r\n");

    expect($preview->rows)->toHaveCount(2)
        ->and($preview->rows[0]->action)->toBe('create')
        ->and($preview->rows[0]->data)->toBe(['name' => 'Ana', 'dupr_id' => '8DPLX8', 'dupr_rating' => '4.250'])
        ->and($preview->rows[0]->line)->toBe(2)
        ->and($preview->rows[1]->line)->toBe(4)
        ->and($preview->rows[1]->data)->toBe(['name' => 'Bo', 'dupr_id' => null, 'dupr_rating' => null]);
});

test('headers are case-insensitive, any order, and only name is required', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "DUPR_Rating, NAME ,extra\n3.5,Cy,ignored\n");
    expect($preview->rows[0]->data)->toBe(['name' => 'Cy', 'dupr_id' => null, 'dupr_rating' => '3.500']);

    $preview = importPreview($club, "name\nDee\n");
    expect($preview->rows[0]->action)->toBe('create');
});

test('a missing name column is a file error', function () {
    $club = Club::factory()->create();

    expect(fn () => importPreview($club, "dupr_id,dupr_rating\n8DPLX8,4\n"))->toThrow(ValidationException::class);
    expect(fn () => importPreview($club, ''))->toThrow(ValidationException::class);
    expect(fn () => importPreview($club, "name,name\nA,B\n"))->toThrow(ValidationException::class);
});

test('non UTF-8 and UTF-16 files are rejected', function () {
    $club = Club::factory()->create();

    expect(fn () => importPreview($club, "name\nJos\xE9\n"))->toThrow(ValidationException::class);
    expect(fn () => importPreview($club, "\xFF\xFEn\0a\0"))->toThrow(ValidationException::class);
    expect(importPreview($club, "name\nJos\xC3\xA9\n")->rows[0]->data['name'])->toBe("Jos\u{e9}");
});

test('size and row limits are enforced', function () {
    $club = Club::factory()->create();

    $rows = implode("\n", array_map(fn ($i) => "Player {$i}", range(1, 1000)));
    expect(importPreview($club, "name\n{$rows}\n")->rows)->toHaveCount(1000);

    $rows .= "\nPlayer 1001";
    expect(fn () => importPreview($club, "name\n{$rows}\n"))->toThrow(ValidationException::class);

    expect(fn () => importPreview($club, "name\n".str_repeat('x', 1024 * 1024 + 1)))->toThrow(ValidationException::class);
});

test('uploaded files are accepted', function () {
    $club = Club::factory()->create();
    $file = UploadedFile::fake()->createWithContent('roster.csv', "name\nEve\n");

    $preview = app(RosterImportService::class)->preview($club, $file);

    expect($preview->rows)->toHaveCount(1);
});

test('rows are validated', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "name,dupr_id,dupr_rating\n,,3.0\nA,SHORT,\nB,,1.999\nC,,8.001\nD,,3.1234\nE,,abc\nF,,2\nG,,8\n");
    $rows = rowsByLine($preview);

    foreach ([2, 3, 4, 5, 6, 7] as $line) {
        expect($rows[$line]->action)->toBe('error', "line {$line}");
    }
    expect($rows[8]->action)->toBe('create')
        ->and($rows[9]->action)->toBe('create')
        ->and($rows[3]->messages)->toContain('DUPR ID must be 6 letters or digits.');
});

test('players match by DUPR ID first, even when the name differs', function () {
    $club = Club::factory()->create();
    $byId = Player::factory()->for($club)->rated(3.0, 'AAAAAA')->create(['name' => 'Old Name']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nNew Name,aaaaaa,4.5\n");

    expect($preview->rows[0]->action)->toBe('update')
        ->and($preview->rows[0]->playerId)->toBe($byId->id);

    $result = app(RosterImportService::class)->commit($club, $preview);

    $byId->refresh();
    expect($result->updated)->toBe(1)
        ->and($byId->name)->toBe('New Name')
        ->and($byId->stars)->toBe(6)
        ->and($club->players()->count())->toBe(1);
});

test('a DUPR match cannot be renamed onto another name', function () {
    $club = Club::factory()->create();
    $byId = Player::factory()->for($club)->rated(3.0, 'AAAAAA')->create(['name' => 'Old Name']);
    Player::factory()->for($club)->manual(2)->create(['name' => 'New Name']);

    $preview = importPreview($club, 'name,dupr_id,dupr_rating
new name,aaaaaa,4.5
');
    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($preview->rows[0]->action)->toBe('error')
        ->and($result->updated)->toBe(0)
        ->and($byId->fresh()->name)->toBe('Old Name');
});

test('players match by case-insensitive trimmed name', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->manual(3)->create(['name' => 'Sam Smith']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\n  sam SMITH ,BBBBBB,3.6\n");
    $result = app(RosterImportService::class)->commit($club, $preview);

    $player->refresh();
    expect($result->updated)->toBe(1)
        ->and($player->name)->toBe('Sam Smith')
        ->and($player->dupr_id)->toBe('BBBBBB')
        ->and($player->rating_source)->toBe(RatingSource::Dupr)
        ->and($player->stars)->toBe(4)
        ->and($club->players()->count())->toBe(1);
});

test('duplicates inside the file are errors', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, implode("\n", [
        'name,dupr_id,dupr_rating',
        'Ann,AAAAAA,',
        'Bob,aaaaaa,',     // same DUPR ID
        'Cat,,',
        'cat ,,',          // same name, no IDs
        'Dan,DDDDDD,',
        'dan,EEEEEE,',     // same name (any case), different IDs: still one name per club
        'Eve,FFFFFF,',
        'Eve,,',           // same name, one without ID
    ]));
    $rows = rowsByLine($preview);

    expect($rows[2]->action)->toBe('create')
        ->and($rows[3]->action)->toBe('error')
        ->and($rows[4]->action)->toBe('create')
        ->and($rows[5]->action)->toBe('error')
        ->and($rows[6]->action)->toBe('create')
        ->and($rows[7]->action)->toBe('error')   // names are unique per club even with different DUPR IDs
        ->and($rows[8]->action)->toBe('create')
        ->and($rows[9]->action)->toBe('error');
});

test('two rows targeting the same existing player are an error', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->rated(3.0, 'AAAAAA')->create(['name' => 'Zed']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nZed,AAAAAA,3.2\nZed,,3.4\n");

    expect($preview->rows[0]->action)->toBe('update')
        ->and($preview->rows[1]->action)->toBe('error');
});

test('unchanged rows are skipped and blanks never clear existing values', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->rated(4.0, 'AAAAAA')->create(['name' => 'Kim']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nKim,AAAAAA,4.000\nkim,,\n");

    expect($preview->rows[0]->action)->toBe('skip');
    // Second row is also the same player, so flagged rather than skipped.
    expect($preview->rows[1]->action)->toBe('error');

    $result = app(RosterImportService::class)->commit($club, $preview);
    $player->refresh();
    expect($result->skipped)->toBe(1)
        ->and($player->dupr_id)->toBe('AAAAAA')
        ->and($player->dupr_rating)->toBe('4.000');
});

test('imported players get stars from the club bands or the default', function () {
    $club = Club::factory()->create(['star_bands' => [3.0, 3.5, 4.0, 4.5, 5.0]]);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nLow,,2.9\nMid,,3.6\nTop,,5.1\nUnrated,,\n");
    $result = app(RosterImportService::class)->commit($club, $preview);

    $stars = $club->players()->pluck('stars', 'name')->all();
    expect($result->created)->toBe(4)
        ->and($stars)->toBe(['Low' => 1, 'Mid' => 3, 'Top' => 6, 'Unrated' => 1]);

    $unrated = $club->players()->where('name', 'Unrated')->firstOrFail();
    expect($unrated->rating_source)->toBe(RatingSource::Manual)
        ->and($club->players()->where('name', 'Mid')->firstOrFail()->rating_source)->toBe(RatingSource::Dupr);
});

test('an existing unrated player keeps their stars when the file has no rating', function () {
    $club = Club::factory()->create();
    $player = Player::factory()->for($club)->manual(5)->create(['name' => 'Pat']);

    $preview = importPreview($club, "name,dupr_id\nPat,CCCCCC\n");
    app(RosterImportService::class)->commit($club, $preview);

    $player->refresh();
    expect($player->stars)->toBe(5)
        ->and($player->dupr_id)->toBe('CCCCCC')
        ->and($player->rating_source)->toBe(RatingSource::Manual);
});

test('another club is never matched or modified', function () {
    $club = Club::factory()->create();
    $other = Club::factory()->create();
    $theirs = Player::factory()->for($other)->rated(3.0, 'AAAAAA')->create(['name' => 'Shared']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nShared,AAAAAA,5.0\n");
    expect($preview->rows[0]->action)->toBe('create');

    $result = app(RosterImportService::class)->commit($club, $preview);

    $theirs->refresh();
    expect($result->created)->toBe(1)
        ->and($theirs->dupr_rating)->toBe('3.000')
        ->and($theirs->name)->toBe('Shared')
        ->and($club->players()->count())->toBe(1)
        ->and($other->players()->count())->toBe(1);
});

test('commit applies only valid rows and refuses another clubs preview', function () {
    $club = Club::factory()->create();
    $other = Club::factory()->create();

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nGood,,3.0\n,,4.0\nAlso Good,,\n");
    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($result->created)->toBe(2)->and($result->errors)->toBe(1)
        ->and($club->players()->count())->toBe(2);

    expect(fn () => app(RosterImportService::class)->commit($other, $preview))->toThrow(InvalidArgumentException::class);
});

test('stale previews are detected at commit', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "name,dupr_id\nNew,ZZZZZZ\n");
    Player::factory()->for($club)->rated(3.0, 'ZZZZZZ')->create();

    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($result->created)->toBe(0)->and($result->errors)->toBe(1)->and($result->notes)->toHaveCount(1);
});

test('a preview survives array serialization', function () {
    $club = Club::factory()->create();
    $preview = importPreview($club, "name,dupr_id,dupr_rating\nAna,AAAAAA,3.0\n");

    $restored = RosterImportPreview::fromArray(json_decode((string) json_encode($preview->toArray()), true));

    expect($restored)->toEqual($preview);
});
