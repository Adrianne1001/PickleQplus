<?php

use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImport\RosterImportPreviewStore;
use App\Services\RosterImport\RosterImportRow;
use App\Services\RosterImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function storedPreview(Club $club): RosterImportPreview
{
    return new RosterImportPreview($club->id, [
        new RosterImportRow(2, RosterImportRow::CREATE, [], ['name' => 'Ana', 'dupr_id' => null, 'dupr_rating' => null]),
    ]);
}

test('a huge file of tiny lines fails the row limit quickly without exhausting memory', function () {
    $club = Club::factory()->create();
    $path = csvFile("name\n".str_repeat("a\n", 520_000));
    expect(filesize($path))->toBeLessThanOrEqual(1024 * 1024);

    $before = memory_get_usage();
    $start = microtime(true);

    expect(fn () => app(RosterImportService::class)->preview($club, $path))
        ->toThrow(ValidationException::class, 'more than 1000 rows');

    expect(microtime(true) - $start)->toBeLessThan(5.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1024 * 1024);
});

test('blank lines do not count towards the row limit', function () {
    $club = Club::factory()->create();
    config(['pickleq.import_max_rows' => 3]);

    $preview = importPreview($club, "name\nA\n\n\n\nB\n\nC\n");

    expect($preview->rows)->toHaveCount(3);
    expect(fn () => importPreview($club, "name\nA\nB\nC\nD\n"))->toThrow(ValidationException::class);
});

test('long names on error rows are truncated with an ellipsis', function () {
    $club = Club::factory()->create();

    $preview = importPreview($club, "name\n".str_repeat('n', 5000)."\n");
    $row = $preview->rows[0];

    expect($row->action)->toBe(RosterImportRow::ERROR)
        ->and(mb_strlen($row->data['name']))->toBe(120)
        ->and(mb_substr($row->data['name'], -1))->toBe("\u{2026}");
});

test('rows matching an inactive player carry a message', function () {
    $club = Club::factory()->create();
    $inactive = Player::factory()->for($club)->inactive()->create(['name' => 'Sleepy Sam']);
    Player::factory()->for($club)->inactive()->create(['name' => 'Quiet Quinn']);

    $preview = importPreview($club, "name,dupr_id,dupr_rating\nSleepy Sam,,3.5\nQuiet Quinn,,\n");
    $rows = rowsByLine($preview);

    expect($rows[2]->action)->toBe(RosterImportRow::UPDATE)
        ->and($rows[2]->messages)->toBe([RosterImportService::INACTIVE_MATCH])
        ->and($rows[3]->action)->toBe(RosterImportRow::SKIP)
        ->and($rows[3]->messages)->toContain(RosterImportService::INACTIVE_MATCH);

    app(RosterImportService::class)->commit($club, $preview);
    expect($inactive->fresh()?->active)->toBeFalse();
});

test('a per-row failure at commit time becomes an error row and the rest still commit', function () {
    $club = Club::factory()->create();
    $preview = importPreview($club, "name,dupr_id,dupr_rating\nFirst,,\nRacer,ABC123,\nLast,,\n");

    // A concurrent writer takes the DUPR ID between the prefetch and the insert.
    Player::creating(function (Player $player) use ($club): void {
        if ($player->name === 'Racer') {
            DB::table('players')->insert([
                'club_id' => $club->id, 'public_id' => 'raceother01', 'name' => 'Other', 'dupr_id' => 'ABC123', 'stars' => 2,
                'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    try {
        $result = app(RosterImportService::class)->commit($club, $preview);
    } finally {
        Player::flushEventListeners();
    }

    expect($result->created)->toBe(2)
        ->and($result->errors)->toBe(1)
        ->and($result->notes)->toHaveCount(1)
        ->and($result->notes[0])->toStartWith('Line 3:')
        ->and($club->players()->whereIn('name', ['First', 'Last'])->count())->toBe(2)
        ->and($club->players()->where('name', 'Racer')->exists())->toBeFalse();
});

test('committing 50 rows does not issue several queries per row', function () {
    $club = Club::factory()->create();
    $existing = collect(range(1, 25))->map(fn ($i) => Player::factory()->for($club)->create(['name' => "Old {$i}"]));
    $lines = $existing->map(fn (Player $p): string => "{$p->name},,3.5")->all();
    foreach (range(1, 25) as $i) {
        $lines[] = "New {$i},,4.0";
    }
    $preview = importPreview($club, "name,dupr_id,dupr_rating\n".implode("\n", $lines)."\n");

    DB::enableQueryLog();
    $result = app(RosterImportService::class)->commit($club, $preview);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result->created)->toBe(25)->and($result->updated)->toBe(25)
        ->and($queries)->toBeLessThan(50 * 5);
});

test('preview store round-trips a preview', function () {
    $user = User::factory()->create();
    $club = Club::factory()->create();
    $store = app(RosterImportPreviewStore::class);

    $id = $store->put($user, $club, storedPreview($club));

    expect($id)->not->toBe('')
        ->and($store->get($user, $club, $id)?->toArray())->toBe(storedPreview($club)->toArray());
});

test('another user or another club gets null from the preview store', function () {
    $user = User::factory()->create();
    $club = Club::factory()->create();
    $store = app(RosterImportPreviewStore::class);
    $id = $store->put($user, $club, storedPreview($club));

    expect($store->get(User::factory()->create(), $club, $id))->toBeNull()
        ->and($store->get($user, Club::factory()->create(), $id))->toBeNull()
        ->and($store->get($user, $club, 'nope'))->toBeNull();
});

test('a preview built for a different club is rejected even under a matching key', function () {
    $user = User::factory()->create();
    $club = Club::factory()->create();
    $other = Club::factory()->create();
    $store = app(RosterImportPreviewStore::class);

    $id = $store->put($user, $club, storedPreview($other));

    expect($store->get($user, $club, $id))->toBeNull();
});

test('previews expire after the configured ttl', function () {
    config(['pickleq.import_preview_ttl_minutes' => 10]);
    $user = User::factory()->create();
    $club = Club::factory()->create();
    $store = app(RosterImportPreviewStore::class);
    $id = $store->put($user, $club, storedPreview($club));

    $this->travel(9)->minutes();
    expect($store->get($user, $club, $id))->not->toBeNull();

    $this->travel(2)->minutes();
    expect($store->get($user, $club, $id))->toBeNull();
});

test('forget removes a stored preview', function () {
    $user = User::factory()->create();
    $club = Club::factory()->create();
    $store = app(RosterImportPreviewStore::class);
    $id = $store->put($user, $club, storedPreview($club));

    $store->forget($user, $club, $id);

    expect($store->get($user, $club, $id))->toBeNull();
});

test('commit turns a CREATE row into an error when the name was added since the preview', function () {
    $club = Club::factory()->create();
    $preview = importPreview($club, "name,dupr_id,dupr_rating\nAna Lopez,,\nBen Cruz,,\n");
    expect(rowsByLine($preview)[2]->action)->toBe(RosterImportRow::CREATE);

    // Another staff member adds the same person (different case) after the preview.
    Player::factory()->for($club)->create(['name' => 'ANA LOPEZ']);

    $result = app(RosterImportService::class)->commit($club, $preview);

    expect($result->created)->toBe(1)
        ->and($result->errors)->toBe(1)
        ->and($result->notes)->toBe(['Line 2: a player with this name was added since the preview.'])
        ->and($club->players()->whereRaw('lower(name) = ?', ['ana lopez'])->count())->toBe(1)
        ->and($club->players()->where('name', 'Ben Cruz')->exists())->toBeTrue();
});
