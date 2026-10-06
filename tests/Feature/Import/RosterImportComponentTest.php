<?php

use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\RosterImport\RosterImportPreviewStore;
use App\Services\RosterImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function importPage(User $user, Club $club)
{
    return Livewire::actingAs($user)->test('pages::clubs.players', ['club' => $club]);
}

function csvUpload(string $contents, string $name = 'roster.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

test('upload shows preview counts and rows with errors', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    Player::factory()->for($club)->create(['name' => 'Existing Ed']);

    $csv = "name,dupr_id,dupr_rating\nNew Nia,,3.5\nexisting ed,,4.0\n,,\nBad Bo,,99\n";

    importPage($owner, $club)
        ->call('startImport')
        ->set('importFile', csvUpload($csv))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="import-preview"')
        ->assertSee('New Nia')
        ->assertSee('Bad Bo')
        ->assertSeeHtml('data-action="error"')
        ->assertSeeHtml('data-test="import-confirm"');

    expect(Player::query()->count())->toBe(1);
});

test('the errors filter narrows the table to error rows', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nGood Gus,,3.5\nBad Bo,,99\n"))
        ->call('previewImport')
        ->assertSee('Good Gus')
        ->set('importErrorsOnly', true)
        ->assertDontSee('Good Gus')
        ->assertSee('Bad Bo');
});

test('commit creates and updates players then refreshes the roster', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    $existing = Player::factory()->for($club)->create(['name' => 'Existing Ed', 'dupr_id' => null, 'dupr_rating' => null, 'stars' => 2]);

    $component = importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nNew Nia,8DPLX8,4.25\nExisting Ed,,3.5\n"))
        ->call('previewImport')
        ->call('commitImport')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="import-result"')
        ->assertSet('importPreviewId', null);

    expect($component->get('importResult'))->toMatchArray(['created' => 1, 'updated' => 1]);

    $nia = $club->players()->where('name', 'New Nia')->firstOrFail();
    expect($nia->dupr_id)->toBe('8DPLX8')
        ->and($existing->fresh()?->dupr_rating)->toBe('3.500');
    $component->assertSee('New Nia');
});

test('staff can import', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    importPage($staff, $club)
        ->set('importFile', csvUpload("name\nStaffed Sam\n"))
        ->call('previewImport')
        ->call('commitImport');

    expect($club->players()->where('name', 'Staffed Sam')->exists())->toBeTrue();
});

test('non-members get 404', function () {
    $club = Club::factory()->withOwner()->create();

    importPage(User::factory()->create(), $club)->assertNotFound();
});

test('imports never touch another club', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    $other = Club::factory()->withOwner()->create();
    $theirs = Player::factory()->for($other)->create(['name' => 'Shared Name', 'dupr_rating' => null, 'dupr_id' => null, 'stars' => 2]);

    importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nShared Name,,4.5\n"))
        ->call('previewImport')
        ->call('commitImport');

    expect($theirs->fresh()?->dupr_rating)->toBeNull()
        ->and($club->players()->where('name', 'Shared Name')->exists())->toBeTrue()
        ->and($other->players()->count())->toBe(1);
});

test('an oversized or wrong-type file is rejected', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    config(['pickleq.import_max_bytes' => 1024]);

    importPage($owner, $club)
        ->set('importFile', UploadedFile::fake()->createWithContent('big.csv', "name\n".str_repeat("Some Player\n", 200)))
        ->call('previewImport')
        ->assertHasErrors('importFile')
        ->assertSet('importPreviewId', null);

    importPage($owner, $club)
        ->set('importFile', UploadedFile::fake()->image('roster.png'))
        ->call('previewImport')
        ->assertHasErrors('importFile');

    importPage($owner, $club)->call('previewImport')->assertHasErrors('importFile');
});

test('a file the service rejects shows its error', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    importPage($owner, $club)
        ->set('importFile', csvUpload("dupr_id,dupr_rating\n8DPLX8,4\n"))
        ->call('previewImport')
        ->assertHasErrors('importFile')
        ->assertSeeHtml('data-test="import-error"');
});

test('confirm is disabled when nothing would change', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\n,,\n"))
        ->call('previewImport')
        ->assertSeeHtml('data-test="import-confirm"')
        ->assertSeeHtml('disabled')
        ->call('commitImport')
        ->assertHasErrors('importFile');

    expect(Player::query()->count())->toBe(0);
});

test('the preview id is locked and cannot be tampered with', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    $component = importPage($owner, $club)
        ->set('importFile', csvUpload("name\nNew Nia\n"))
        ->call('previewImport');

    expect(fn () => $component->set('importPreviewId', 'forged'))->toThrow(Exception::class);
    expect(fn () => $component->set('importPreviewId', null))->toThrow(Exception::class);

    expect(Player::query()->count())->toBe(0);
});

test('the snapshot does not carry the preview rows', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    $component = importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nDistinctive Dora,,3.5\n"))
        ->call('previewImport')
        ->assertSee('Distinctive Dora');

    expect($component->get('importPreviewId'))->toBeString();
    expect(json_encode($component->snapshot))->not->toContain('Distinctive Dora');
});

test('another user cannot use a preview id from a different user', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    $staff = User::factory()->create();
    $club->users()->attach($staff, ['role' => 'staff']);

    $id = importPage($owner, $club)
        ->set('importFile', csvUpload("name\nStolen Sam\n"))
        ->call('previewImport')
        ->get('importPreviewId');

    $store = app(RosterImportPreviewStore::class);
    expect($store->get($staff, $club, $id))->toBeNull();

    // Even if the other user's component somehow held the id, nothing is committed.
    $theirs = importPage($staff, $club);
    (fn () => $this->importPreviewId = $id)->call($theirs->instance());
    $theirs->call('commitImport');

    expect(Player::query()->where('name', 'Stolen Sam')->exists())->toBeFalse();
});

test('an expired preview shows a message and returns to the upload step', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    $component = importPage($owner, $club)
        ->set('importFile', csvUpload("name\nLate Lou\n"))
        ->call('previewImport')
        ->assertSeeHtml('data-test="import-preview"');

    $store = app(RosterImportPreviewStore::class);
    $store->forget($owner, $club, $component->get('importPreviewId'));

    $component->call('$refresh')
        ->assertSee('This preview expired; upload the file again.')
        ->assertSeeHtml('data-test="import-upload-form"')
        ->assertSet('importPreviewId', null);

    expect(Player::query()->count())->toBe(0);
});

test('the sample CSV downloads with the expected header', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    importPage($owner, $club)
        ->call('downloadSample')
        ->assertFileDownloaded('pickleq-roster-sample.csv', "name,dupr_id,dupr_rating\nAna Lopez,8DPLX8,4.25\nBen Cruz,,3.5\nCara Dela Rosa,,\n");
});

test('the preview UI shows the inactive-match message on update and skip rows', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    Player::factory()->for($club)->inactive()->create(['name' => 'Sleepy Sam']);
    Player::factory()->for($club)->inactive()->create(['name' => 'Quiet Quinn', 'dupr_rating' => null]);

    $html = importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nSleepy Sam,,3.5\nQuiet Quinn,,\n"))
        ->call('previewImport')
        ->assertHasNoErrors()
        ->html();

    expect(substr_count($html, e(RosterImportService::INACTIVE_MATCH)))->toBe(2)
        ->and($html)->toContain('data-action="update"')
        ->and($html)->toContain('data-action="skip"');
});

test('a row failing at race time shows the error count and the line note in the result UI', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();

    $component = importPage($owner, $club)
        ->set('importFile', csvUpload("name,dupr_id,dupr_rating\nFirst,,\nRacer,ABC123,\nLast,,\n"))
        ->call('previewImport')
        ->assertHasNoErrors();

    Player::creating(function (Player $player) use ($club): void {
        if ($player->name === 'Racer') {
            DB::table('players')->insert([
                'club_id' => $club->id, 'public_id' => 'raceother01', 'name' => 'Other', 'dupr_id' => 'ABC123', 'stars' => 2,
                'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    try {
        $component->call('commitImport');
    } finally {
        Player::flushEventListeners();
    }

    $component->assertSeeHtml('data-test="import-result"')
        ->assertSee('2 added, 0 updated, 0 unchanged, 1 left out.')
        ->assertSeeHtml('data-test="import-notes"')
        ->assertSee('Line 3:');
    expect($club->players()->whereIn('name', ['First', 'Last'])->count())->toBe(2);
});

test('exceeding the row limit through the component shows the file error without a 500', function () {
    $club = Club::factory()->withOwner($owner = User::factory()->create())->create();
    config(['pickleq.import_max_rows' => 5]);

    importPage($owner, $club)
        ->set('importFile', csvUpload("name\n".implode("\n", range(1, 20))."\n"))
        ->call('previewImport')
        ->assertHasErrors('importFile')
        ->assertSeeHtml('data-test="import-error"')
        ->assertSee('more than 5 rows')
        ->assertSet('importPreviewId', null);
});
