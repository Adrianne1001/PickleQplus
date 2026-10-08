<?php

use App\Enums\MatchStatus;
use App\Enums\Team;
use App\Models\Club;
use App\Models\DuprExport;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\ClubService;
use App\Services\Dupr\CsvPublisher;
use App\Services\Dupr\DuprEligibility;
use App\Services\Dupr\DuprExportService;
use App\Services\Dupr\DuprPublisher;
use App\Services\Dupr\DuprSkipReason;
use App\Services\MatchService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Add a match to the session with four players (A1, A2, B1, B2).
 *
 * @param  list<Player>|null  $players
 * @param  array<string, mixed>  $attrs
 */
function duprMatch(PlaySession $session, ?array $players = null, array $attrs = []): GameMatch
{
    $match = GameMatch::factory()->for($session)->create($attrs + [
        'status' => MatchStatus::Done,
        'team_a_score' => 11,
        'team_b_score' => 7,
        'finished_at' => now(),
    ]);
    $players ??= Player::factory()->count(4)->for($session->club)->sequence(
        fn () => ['dupr_id' => strtoupper(substr(md5((string) microtime(true).random_int(0, 99999)), 0, 6))],
    )->create()->all();
    foreach ([[Team::A, 1], [Team::A, 2], [Team::B, 1], [Team::B, 2]] as $i => [$team, $slot]) {
        MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $players[$i]->id, 'team' => $team, 'slot' => $slot]);
    }

    return $match;
}

/** @return array{0: User, 1: Club, 2: PlaySession} */
function duprSetup(): array
{
    [$user, $club] = memberOf();
    $club->update(['name' => 'Pickle Club']);
    $session = PlaySession::factory()->for($club)->ended()->create(['name' => 'Night', 'date' => '2026-09-29']);

    return [$user, $club, $session];
}

test('the publisher interface is bound to the CSV publisher', function () {
    expect(app(DuprPublisher::class))->toBeInstanceOf(CsvPublisher::class);
});

test('eligibility lists eligible matches and skipped ones with reasons', function () {
    [, $club, $session] = duprSetup();
    $ok = duprMatch($session);
    $ann = Player::factory()->for($club)->create(['name' => 'Ann NoId', 'dupr_id' => null]);
    $bob = Player::factory()->for($club)->create(['name' => 'Bob NoId', 'dupr_id' => null]);
    $n = 0;
    $withId = function () use ($club, &$n) {
        return Player::factory()->for($club)->create(['dupr_id' => 'ID'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT)]);
    };
    $missing1 = duprMatch($session, [$ann, $withId(), $withId(), $withId()]);
    $missing2 = duprMatch($session, [$ann, $bob, $withId(), $withId()]);
    $exported = duprMatch($session, null, ['dupr_exported_at' => now()]);
    $notEligible = duprMatch($session, null, ['dupr_eligible' => false]);
    duprMatch($session, null, ['status' => MatchStatus::Void]);
    duprMatch($session, null, ['status' => MatchStatus::Staged, 'team_a_score' => null, 'team_b_score' => null]);
    duprMatch($session, null, ['status' => MatchStatus::Playing, 'team_a_score' => null, 'team_b_score' => null]);

    $summary = app(DuprEligibility::class)->summarize($session);

    expect($summary->eligible->pluck('id')->all())->toBe([$ok->id])
        ->and($summary->eligible->first()->matchPlayers->first()->relationLoaded('player'))->toBeTrue()
        ->and($summary->skippedCount())->toBe(4);

    $byMatch = collect($summary->skipped)->keyBy(fn ($s) => $s->match->id);
    expect($byMatch[$missing1->id]->reason)->toBe(DuprSkipReason::MissingDuprId)
        ->and($byMatch[$missing1->id]->missingNames())->toBe(['Ann NoId'])
        ->and($byMatch[$missing2->id]->missingNames())->toBe(['Ann NoId', 'Bob NoId'])
        ->and($byMatch[$exported->id]->reason)->toBe(DuprSkipReason::AlreadyExported)
        ->and($byMatch[$notEligible->id]->reason)->toBe(DuprSkipReason::NotEligible);

    expect($summary->missingPlayers)->toHaveCount(2)
        ->and($summary->missingPlayers[0]->player->name)->toBe('Ann NoId')
        ->and($summary->missingPlayers[0]->blockedMatches)->toBe(2)
        ->and($summary->missingPlayers[1]->player->name)->toBe('Bob NoId')
        ->and($summary->missingPlayers[1]->blockedMatches)->toBe(1);
});

test('eligibility ignores other sessions and treats a blank DUPR id as missing', function () {
    [, $club, $session] = duprSetup();
    $other = PlaySession::factory()->for($club)->ended()->create();
    duprMatch($other);
    $blank = Player::factory()->for($club)->create(['dupr_id' => null]);
    $ids = Player::factory()->count(3)->for($club)->sequence(['dupr_id' => 'AAA111'], ['dupr_id' => 'AAA222'], ['dupr_id' => 'AAA333'])->create();
    $m = duprMatch($session, [$blank, ...$ids->all()]);
    GameMatch::query()->whereKey($m->id)->update(['team_b_score' => null]);
    $incomplete = duprMatch($session, null, ['team_a_score' => null]);

    $summary = app(DuprEligibility::class)->summarize($session);

    expect($summary->eligibleCount())->toBe(0)
        ->and($summary->skippedCount())->toBe(2)
        ->and(collect($summary->skipped)->firstWhere(fn ($s) => $s->match->id === $incomplete->id)->reason)->toBe(DuprSkipReason::Incomplete);
});

test('export writes the file, creates the record and stamps the matches', function () {
    Storage::fake('local');
    [$user, $club, $session] = duprSetup();
    $a = duprMatch($session);
    $b = duprMatch($session);
    duprMatch($session, null, ['dupr_eligible' => false]);

    $export = app(DuprExportService::class)->export($session, $user);

    expect($export->match_count)->toBe(2)
        ->and($export->user_id)->toBe($user->id)
        ->and($export->play_session_id)->toBe($session->id)
        ->and($export->file_path)->toBe("dupr-exports/{$club->id}/{$export->id}.csv");
    Storage::disk('local')->assertExists($export->file_path);

    $lines = explode("\n", Storage::disk('local')->get($export->file_path));
    expect($lines)->toHaveCount(4)
        ->and($lines[1])->toStartWith('D,Night,2026-09-29,')
        ->and($lines[1])->toContain(",{$club->name},SIDEOUT,11,7,,,,,,,,");

    foreach ([$a, $b] as $m) {
        $m->refresh();
        expect($m->dupr_exported_at)->not->toBeNull()->and($m->dupr_export_id)->toBe($export->id);
    }
    expect($export->matches()->count())->toBe(2)
        ->and($session->duprExports()->count())->toBe(1);
});

test('export refuses a session that has not ended', function (string $state) {
    Storage::fake('local');
    [$user, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->{$state}()->create();
    duprMatch($session);

    expect(fn () => app(DuprExportService::class)->export($session, $user))->toThrow(ValidationException::class);
    expect(DuprExport::query()->count())->toBe(0)
        ->and(GameMatch::query()->whereNotNull('dupr_exported_at')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['live']);

test('export refuses a draft session', function () {
    [$user, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create();
    duprMatch($session);

    expect(fn () => app(DuprExportService::class)->export($session, $user))->toThrow(ValidationException::class);
});

test('export refuses when nothing is eligible and creates nothing', function () {
    Storage::fake('local');
    [$user, , $session] = duprSetup();
    duprMatch($session, null, ['dupr_eligible' => false]);

    expect(fn () => app(DuprExportService::class)->export($session, $user))->toThrow(ValidationException::class);
    expect(DuprExport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a second export does not include already exported matches', function () {
    Storage::fake('local');
    [$user, , $session] = duprSetup();
    duprMatch($session);
    $first = app(DuprExportService::class)->export($session, $user);

    expect(fn () => app(DuprExportService::class)->export($session, $user))->toThrow(ValidationException::class);

    $late = duprMatch($session);
    $second = app(DuprExportService::class)->export($session->fresh(), $user);

    expect($second->id)->not->toBe($first->id)
        ->and($second->match_count)->toBe(1)
        ->and($late->fresh()->dupr_export_id)->toBe($second->id)
        ->and($first->matches()->count())->toBe(1);
    Storage::disk('local')->assertExists($first->file_path);
    Storage::disk('local')->assertExists($second->file_path);
});

test('a failing publisher leaves no record, no stamp and no file', function () {
    Storage::fake('local');
    [$user, , $session] = duprSetup();
    $m = duprMatch($session);
    // Fail after the file is written and the row created, while stamping the matches.
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'dupr_export_id')
            && str_starts_with(strtolower($query->sql), 'update')) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(DuprExportService::class)->export($session, $user))->toThrow(RuntimeException::class);
    expect(DuprExport::query()->count())->toBe(0)
        ->and($m->fresh()->dupr_exported_at)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('exported matches cannot be edited, voided or undone', function () {
    Storage::fake('local');
    [$user, , $session] = duprSetup();
    $m = duprMatch($session);
    app(DuprExportService::class)->export($session, $user);
    $m->refresh();

    expect(fn () => app(MatchService::class)->editScore($session, $m, 11, 3))->toThrow(ValidationException::class, 'already exported');
    expect(fn () => app(MatchService::class)->void($session, $m))->toThrow(ValidationException::class, 'already exported');
    expect(fn () => app(MatchService::class)->undoLast($session))->toThrow(ValidationException::class);

    expect($m->fresh()->status)->toBe(MatchStatus::Done)->and($m->fresh()->team_b_score)->toBe(7);
});

test('undo last result refuses an exported match on a live session', function () {
    [, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->live()->create();
    $m = duprMatch($session, null, ['dupr_exported_at' => now()]);

    expect(fn () => app(MatchService::class)->undoLast($session))->toThrow(ValidationException::class, 'already exported');
    expect($m->fresh()->status)->toBe(MatchStatus::Done);
});

// --- download ---

/** @return array{0: User, 1: Club, 2: PlaySession, 3: DuprExport} */
function duprExported(): array
{
    Storage::fake('local');
    [$user, $club, $session] = duprSetup();
    duprMatch($session);
    $export = app(DuprExportService::class)->export($session, $user);

    return [$user, $club, $session, $export];
}

function duprUrl(Club $club, PlaySession $session, DuprExport|int $export): string
{
    return route('clubs.sessions.dupr.download', [$club, $session, $export instanceof DuprExport ? $export->id : $export]);
}

test('an owner downloads the stored file with the right filename and content', function () {
    [$user, $club, $session, $export] = duprExported();

    $response = $this->actingAs($user)->get(duprUrl($club, $session, $export));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload("dupr-{$club->slug}-2026-09-29-{$export->id}.csv");
    expect($response->streamedContent())->toBe(Storage::disk('local')->get($export->file_path));
});

test('staff can download', function () {
    [, $club, $session, $export] = duprExported();
    $staff = User::factory()->create();
    $club->users()->attach($staff->id, ['role' => 'staff']);
    User::flushRoleCache();

    $this->actingAs($staff)->get(duprUrl($club, $session, $export))->assertOk();
});

test('guests are sent to login and non-members get 404', function () {
    [, $club, $session, $export] = duprExported();

    $this->get(duprUrl($club, $session, $export))->assertRedirect();
    $this->actingAs(User::factory()->create())->get(duprUrl($club, $session, $export))->assertNotFound();
});

test('another club or session cannot reach the export', function () {
    [$user, $club, $session, $export] = duprExported();
    [$otherUser, $otherClub] = memberOf();
    $otherSession = PlaySession::factory()->for($otherClub)->ended()->create();
    $sameClubOther = PlaySession::factory()->for($club)->ended()->create();

    // Another club's member using our export through their own club and session.
    $this->actingAs($otherUser)->get(duprUrl($otherClub, $otherSession, $export))->assertNotFound();
    // Our session under their club slug.
    $this->actingAs($otherUser)->get(duprUrl($otherClub, $session, $export))->assertNotFound();
    // Export belongs to a different session of the same club.
    $this->actingAs($user)->get(duprUrl($club, $sameClubOther, $export))->assertNotFound();
    // Unknown export.
    $this->actingAs($user)->get(duprUrl($club, $session, 999999))->assertNotFound();
});

test('a missing file is a 404', function () {
    [$user, $club, $session, $export] = duprExported();
    Storage::disk('local')->delete($export->file_path);

    $this->actingAs($user)->get(duprUrl($club, $session, $export))->assertNotFound();
});

test('the exported line maps A1 A2 B1 B2 by team and slot with names as entered and uppercase ids', function () {
    Storage::fake('local');
    [$user, $club, $session] = duprSetup();
    $mk = fn (string $name, string $id) => Player::factory()->for($club)->create(['name' => $name, 'dupr_id' => $id]);
    $a1 = $mk('Ann Alpha', 'aaa111');
    $a2 = $mk('Ace', 'bbb222');
    $b1 = $mk('Cy Gamma', 'ccc333');
    $b2 = $mk('Di Delta', 'ddd444');
    $match = GameMatch::factory()->for($session)->create([
        'status' => MatchStatus::Done, 'team_a_score' => 9, 'team_b_score' => 11, 'finished_at' => now(),
    ]);
    // Inserted out of order: B2 first.
    foreach ([[$b2, Team::B, 2], [$a2, Team::A, 2], [$b1, Team::B, 1], [$a1, Team::A, 1]] as [$p, $team, $slot]) {
        MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $p->id, 'team' => $team, 'slot' => $slot]);
    }

    $export = app(DuprExportService::class)->export($session, $user);

    $lines = explode("\n", Storage::disk('local')->get($export->file_path));
    expect($lines[1])->toBe('D,Night,2026-09-29,Ann Alpha,AAA111,,Ace,BBB222,,Cy Gamma,CCC333,,Di Delta,DDD444,,Pickle Club,SIDEOUT,9,11,,,,,,,,');
});

test('deleting a club removes its DUPR export files', function () {
    Storage::fake('local');
    [$user, $club, $session] = duprSetup();
    duprMatch($session);
    $export = app(DuprExportService::class)->export($session, $user);
    Storage::disk('local')->assertExists($export->file_path);

    app(ClubService::class)->delete($club);

    Storage::disk('local')->assertMissing($export->file_path);
    expect(DuprExport::query()->count())->toBe(0);
});
