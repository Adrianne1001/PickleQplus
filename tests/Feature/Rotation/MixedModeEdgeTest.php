<?php

use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\PlayerService;
use App\Services\PublicSessionView;
use App\Services\RosterImportService;
use App\Services\SelfCheckInService;
use App\Services\SessionBoard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('a gender that changes between loading and swapping cannot slip past the guard', function () {
    [$session, $players] = mixedBoard('MMWWMW');
    $match = stagedOf($session)[0];
    $out = genderedPlayerOf($match, 'man');
    $in = $players[4];
    expect($in->gender)->toBe(Gender::Man);

    // Another request turns them into a woman after the models were loaded.
    Player::query()->whereKey($in->id)->update(['gender' => 'woman']);

    expect(fn () => app(MatchService::class)->swap($session, $match, $out, $in))->toThrow(ValidationException::class)
        ->and(matchIds($match->fresh()))->not->toContain($in->id);
});

test('swap works when the outgoing player has no gender', function () {
    [$session, $players] = mixedBoard('MMWWMW');
    $match = stagedOf($session)[0];
    $out = genderedPlayerOf($match, 'man');
    Player::query()->whereKey($out->id)->update(['gender' => null]);

    app(MatchService::class)->swap($session, $match, $out->fresh(), $players[4]);

    expect(matchIds($match->fresh()))->toContain($players[4]->id)->not->toContain($out->id);
});

test('remove falls back to a gendered player when the remaining teammate has no gender', function () {
    [$session] = mixedBoard('MMWWMWMW');
    $match = stagedOf($session)[0];
    $rows = $match->matchPlayers()->get();
    $removeRow = $rows->first();
    $mate = $rows->first(fn ($r) => $r->team === $removeRow->team && $r->player_id !== $removeRow->player_id);
    Player::query()->whereKey($mate->player_id)->update(['gender' => null]);

    app(MatchService::class)->remove($session, $match, Player::findOrFail($removeRow->player_id));

    $ids = matchIds($match->fresh());
    expect($ids)->not->toContain($removeRow->player_id)->toHaveCount(4);
    $new = collect($ids)->diff($rows->pluck('player_id'))->first();
    expect(Player::findOrFail($new)->gender)->not->toBeNull();
});

test('setting the gender of a playing player keeps the match going', function () {
    [$session] = mixedBoard('MMWW', ['auto_fill' => true]);
    $playing = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->firstOrFail();
    $player = genderedPlayerOf($playing, 'man');

    app(CheckInService::class)->setGender($session, $player, 'woman');

    expect($playing->fresh()->status)->toBe(MatchStatus::Playing)
        ->and(entryOf($session, $player)->status)->toBe(SessionPlayerStatus::Playing);
});

test('self check-in on the already-checked-in path sets a gender and refills a mixed session', function () {
    [$session, $players] = mixedBoard('MMWN');
    expect(stagedOf($session))->toHaveCount(0);

    $result = app(SelfCheckInService::class)->checkIn($session, (string) $session->checkin_token, $players[3]->public_id, null, '1.1.1.1', 'woman');

    expect($result['result'])->toBe('already_checked_in')
        ->and(stagedOf($session))->toHaveCount(1);
});

test('a roster import that changes a gender voids the staged match after it commits', function () {
    [$session] = mixedBoard('MMWWWW');
    $first = stagedOf($session)[0];
    $man = genderedPlayerOf($first, 'man');
    $man->forceFill(['name' => 'Manny Import'])->save();

    $preview = importPreview($session->club, "name,gender\nManny Import,woman\n");
    app(RosterImportService::class)->commit($session->club, $preview);

    expect($man->fresh()->gender)->toBe(Gender::Woman)
        ->and($first->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(0);
});

test('nothing is voided when the import transaction rolls back', function () {
    [$session] = mixedBoard('MMWWWW');
    $first = stagedOf($session)[0];
    $man = genderedPlayerOf($first, 'man');
    $man->forceFill(['name' => 'Manny Import'])->save();
    $preview = importPreview($session->club, "name,gender\nManny Import,woman\n");

    try {
        DB::transaction(function () use ($session, $preview) {
            app(RosterImportService::class)->commit($session->club, $preview);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($man->fresh()->gender)->toBe(Gender::Man)
        ->and($first->fresh()->status)->toBe(MatchStatus::Staged);
});

test('a failure while syncing one player never reaches the caller', function () {
    [$session] = mixedBoard('MMWWWW');
    $man = genderedPlayerOf(stagedOf($session)[0], 'man');
    $this->mock(CheckInService::class, fn ($m) => $m->shouldReceive('gendersChanged')->andThrow(new RuntimeException('boom')));

    $updated = app(PlayerService::class)->update($man, ['gender' => 'woman']);

    expect($updated->gender)->toBe(Gender::Woman);
});

test('mixed estimates are null when the other gender cannot pair, on staff and public views', function () {
    [$session] = mixedBoard('MMMMM');

    $rows = app(SessionBoard::class)->waiting($session);
    $public = app(PublicSessionView::class)->snapshot($session)['waiting'];

    expect($rows)->toHaveCount(5)
        ->and(collect($rows)->pluck('estimate_minutes')->filter(fn ($e) => $e !== null))->toBeEmpty()
        ->and(collect($public)->pluck('estimate_minutes')->filter(fn ($e) => $e !== null))->toBeEmpty();
});

test('mixed estimates are null for an unpaired own-gender player', function () {
    // One match is staged (2 men, 2 women), leaving 3 men and 2 women waiting: the third man has no partner.
    [$session] = mixedBoard('MMMMMWWWW');
    $men = collect(app(SessionBoard::class)->waiting($session))->where('gender', 'man')->values();

    expect($men)->toHaveCount(3)
        ->and($men[0]['estimate_minutes'])->not->toBeNull()
        ->and($men[2]['estimate_minutes'])->toBeNull();
});

test('unplaceableIn counts from rows already fetched', function () {
    [$session] = mixedBoard('MWNN');
    $board = app(SessionBoard::class);

    expect($board->unplaceableIn($board->waiting($session)))->toBe(2);
});
