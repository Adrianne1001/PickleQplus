<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\Team;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Services\ClubService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** @return array{0: PlaySession, 1: GameMatch} a live session with one playing match */
function playingResultMatch(bool $requireScore): array
{
    [$session] = board(4, ['require_score' => $requireScore]);
    $match = stagedOf($session)[0];
    app(MatchService::class)->startMatch($session, $match);

    return [$session, $match->fresh()];
}

function resultErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// --- finish ---

test('a session that does not require a score finishes with the winner only', function () {
    [$session, $match] = playingResultMatch(false);

    app(MatchService::class)->finish($session, $match, Team::B);

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Done)
        ->and($fresh->winner)->toBe(Team::B)
        ->and($fresh->team_a_score)->toBeNull()
        ->and($fresh->team_b_score)->toBeNull()
        ->and($fresh->finished_at)->not->toBeNull();
    foreach ($match->matchPlayers as $row) {
        $entry = entryOf($session, $row->player);
        expect($entry->games_played)->toBe(1)
            ->and($entry->status)->toBe(SessionPlayerStatus::Waiting);
    }
});

test('a session that requires a score refuses a winner-only finish and leaves the match playing', function () {
    [$session, $match] = playingResultMatch(true);

    expect(resultErrors(fn () => app(MatchService::class)->finish($session, $match, Team::A)))->toHaveKey('score')
        ->and($match->fresh()->status)->toBe(MatchStatus::Playing)
        ->and($match->fresh()->winner)->toBeNull();
});

test('scores are both or neither', function (bool $require) {
    [$session, $match] = playingResultMatch($require);

    expect(resultErrors(fn () => app(MatchService::class)->finish($session, $match, Team::A, 11, null)))->toHaveKey('score')
        ->and(resultErrors(fn () => app(MatchService::class)->finish($session, $match, Team::A, null, 5)))->toHaveKey('score')
        ->and($match->fresh()->status)->toBe(MatchStatus::Playing);
})->with([true, false]);

test('given scores must be valid, whatever the setting', function () {
    [$session, $match] = playingResultMatch(false);

    foreach ([[11, 10], [13, 10], [10, 8], [11, 11]] as [$a, $b]) {
        expect(resultErrors(fn () => app(MatchService::class)->finish($session, $match, $a > $b ? Team::A : Team::B, $a, $b)))->toHaveKey('score');
    }
    expect($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('scores that disagree with the winner are refused with a clear message', function () {
    [$session, $match] = playingResultMatch(true);

    $errors = resultErrors(fn () => app(MatchService::class)->finish($session, $match, Team::A, 5, 11));
    expect($errors['score'][0])->toBe("Team A's score must be higher.");

    $errors = resultErrors(fn () => app(MatchService::class)->finish($session, $match, Team::B, 11, 5));
    expect($errors['score'][0])->toBe("Team B's score must be higher.")
        ->and($match->fresh()->status)->toBe(MatchStatus::Playing);
});

test('a winner with agreeing scores is stored, including an extended game', function () {
    [$session, $match] = playingResultMatch(true);

    app(MatchService::class)->finish($session, $match, Team::B, 10, 12);

    expect($match->fresh()->winner)->toBe(Team::B)
        ->and([$match->fresh()->team_a_score, $match->fresh()->team_b_score])->toBe([10, 12]);
});

test('scores are optional for a not-required session and still stored when given', function () {
    [$session, $match] = playingResultMatch(false);

    app(MatchService::class)->finish($session, $match, Team::A, 11, 4);

    expect($match->fresh()->winner)->toBe(Team::A)->and($match->fresh()->team_b_score)->toBe(4);
});

// --- undo ---

test('undo clears the winner along with the scores', function (bool $winnerOnly) {
    [$session, $match] = playingResultMatch($winnerOnly ? false : true);
    $winnerOnly
        ? app(MatchService::class)->finish($session, $match, Team::A)
        : app(MatchService::class)->finish($session, $match, Team::A, 11, 3);

    app(MatchService::class)->undoLast($session);

    $fresh = $match->fresh();
    expect($fresh->status)->toBe(MatchStatus::Playing)
        ->and($fresh->winner)->toBeNull()
        ->and($fresh->team_a_score)->toBeNull()
        ->and($fresh->team_b_score)->toBeNull();
})->with([true, false]);

// --- edit result ---

test('edit result can add a score later to a winner-only match', function () {
    [$session, $match] = playingResultMatch(false);
    app(MatchService::class)->finish($session, $match, Team::A);

    app(MatchService::class)->editResult($session, $match->fresh(), Team::A, 11, 6);

    expect($match->fresh()->winner)->toBe(Team::A)
        ->and([$match->fresh()->team_a_score, $match->fresh()->team_b_score])->toBe([11, 6]);
});

test('edit result can change the winner and drop the scores when not required', function () {
    [$session, $match] = playingResultMatch(false);
    app(MatchService::class)->finish($session, $match, Team::A, 11, 6);

    app(MatchService::class)->editResult($session, $match->fresh(), Team::B);

    expect($match->fresh()->winner)->toBe(Team::B)->and($match->fresh()->team_a_score)->toBeNull();
});

test('edit result follows the same rules as finish', function () {
    [$session, $match] = playingResultMatch(true);
    app(MatchService::class)->finish($session, $match, Team::A, 11, 6);
    $done = $match->fresh();

    expect(resultErrors(fn () => app(MatchService::class)->editResult($session, $done, Team::A)))->toHaveKey('score')
        ->and(resultErrors(fn () => app(MatchService::class)->editResult($session, $done, Team::A, 11, null)))->toHaveKey('score')
        ->and(resultErrors(fn () => app(MatchService::class)->editResult($session, $done, Team::A, 11, 10)))->toHaveKey('score')
        ->and(resultErrors(fn () => app(MatchService::class)->editResult($session, $done, Team::A, 6, 11))['score'][0])->toBe("Team A's score must be higher.");

    app(MatchService::class)->editResult($session, $done, Team::B, 7, 11);
    expect($match->fresh()->winner)->toBe(Team::B);
});

test('edit result is refused once the match was exported to DUPR and for a match that is not done', function () {
    [$session, $match] = playingResultMatch(false);

    expect(resultErrors(fn () => app(MatchService::class)->editResult($session, $match, Team::A)))->toHaveKey('match');

    app(MatchService::class)->finish($session, $match, Team::A);
    GameMatch::query()->whereKey($match->id)->update(['dupr_exported_at' => now()]);

    expect(resultErrors(fn () => app(MatchService::class)->editResult($session, $match->fresh(), Team::B, 11, 4)))->toHaveKey('match')
        ->and($match->fresh()->winner)->toBe(Team::A);
});

// --- require_score setting ---

test('a new session starts from the club setting and the form value wins', function () {
    $club = Club::factory()->create(['require_score' => false]);
    $svc = app(PlaySessionService::class);

    expect($svc->create($club)->require_score)->toBeFalse()
        ->and($svc->create($club, ['require_score' => true])->require_score)->toBeTrue()
        ->and($svc->create(Club::factory()->create())->require_score)->toBeTrue();
});

test('require_score can change while draft and live, and never on an ended session', function () {
    $svc = app(PlaySessionService::class);
    $session = PlaySession::factory()->for(Club::factory()->create())->create();

    expect($svc->update($session, ['require_score' => false])->require_score)->toBeFalse();

    $session->forceFill(['status' => 'live', 'started_at' => now()])->save();
    expect($svc->update($session->fresh(), ['require_score' => true])->require_score)->toBeTrue();

    // An unrelated update keeps the value.
    expect($svc->update($session->fresh(), ['name' => 'Renamed'])->require_score)->toBeTrue();

    $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
    expect(resultErrors(fn () => $svc->update($session->fresh(), ['require_score' => false])))->toHaveKey('status')
        ->and($session->fresh()->require_score)->toBeTrue();
});

test('changing require_score does not alter recorded results', function () {
    [$session, $match] = playingResultMatch(false);
    app(MatchService::class)->finish($session, $match, Team::A);

    app(PlaySessionService::class)->update($session->fresh(), ['require_score' => true]);

    expect($match->fresh()->status)->toBe(MatchStatus::Done)->and($match->fresh()->winner)->toBe(Team::A);
});

test('the club owner setting persists require_score', function () {
    $club = Club::factory()->create();
    expect($club->fresh()->require_score)->toBeTrue();

    app(ClubService::class)->updateSessionSettings($club, ['require_score' => false]);
    expect($club->fresh()->require_score)->toBeFalse();

    // Other keys leave it alone.
    app(ClubService::class)->updateSessionSettings($club->fresh(), ['allow_concurrent_sessions' => true]);
    expect($club->fresh()->require_score)->toBeFalse();

    expect(resultErrors(fn () => app(ClubService::class)->updateSessionSettings($club, ['require_score' => 'maybe'])))->toHaveKey('require_score');
});

// --- migration ---

test('the migration backfills the winner of done matches that have both scores', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->ended()->create();
    $migration = require database_path('migrations/2026_10_17_000001_add_require_score_and_winner.php');
    $migration->down();
    expect(Schema::hasColumn('matches', 'winner'))->toBeFalse()
        ->and(Schema::hasColumn('clubs', 'require_score'))->toBeFalse()
        ->and(Schema::hasColumn('play_sessions', 'require_score'))->toBeFalse();

    $sessionId = $session->id;
    $insert = fn (string $status, ?int $a, ?int $b): int => DB::table('matches')->insertGetId([
        'play_session_id' => $sessionId, 'status' => $status, 'team_a_score' => $a, 'team_b_score' => $b,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $aWon = $insert('done', 11, 4);
    $bWon = $insert('done', 9, 11);
    $noScore = $insert('done', null, null);
    $partial = $insert('done', 11, null);
    $void = $insert('void', 11, 3);
    $staged = $insert('staged', null, null);

    $migration->up();

    $winner = fn (int $id): ?string => DB::table('matches')->where('id', $id)->value('winner');
    expect($winner($aWon))->toBe('A')
        ->and($winner($bWon))->toBe('B')
        ->and($winner($noScore))->toBeNull()
        ->and($winner($partial))->toBeNull()
        ->and($winner($void))->toBeNull()
        ->and($winner($staged))->toBeNull()
        ->and((bool) DB::table('clubs')->value('require_score'))->toBeTrue()
        ->and((bool) DB::table('play_sessions')->value('require_score'))->toBeTrue();
});

test('the migration backfill leaves void matches and done matches with no scores at no winner', function () {
    $session = PlaySession::factory()->for(Club::factory()->create())->ended()->create();
    $migration = require database_path('migrations/2026_10_17_000001_add_require_score_and_winner.php');
    $migration->down();
    $insert = fn (string $status, ?int $a, ?int $b): int => DB::table('matches')->insertGetId([
        'play_session_id' => $session->id, 'status' => $status, 'team_a_score' => $a, 'team_b_score' => $b,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $void = $insert('void', 11, 2);
    $none = $insert('done', null, null);
    $done = $insert('done', 3, 11);

    $migration->up();

    expect(DB::table('matches')->whereIn('id', [$void, $none])->whereNotNull('winner')->count())->toBe(0)
        ->and(DB::table('matches')->where('id', $done)->value('winner'))->toBe('B');
});
