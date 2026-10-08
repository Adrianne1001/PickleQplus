<?php

use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Enums\SessionPlayerStatus;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\MatchService;
use App\Services\PlayerService;
use App\Services\PublicSessionView;
use App\Services\Rotation\MixedStrategy;
use App\Services\Rotation\RotationStrategies;
use App\Services\SessionBoard;
use Illuminate\Validation\ValidationException;

test('the factory returns the mixed strategy', function () {
    expect(RotationStrategies::for(PlaySession::factory()->make(['rotation_mode' => RotationMode::Mixed])))->toBeInstanceOf(MixedStrategy::class);
});

test('a mixed session stages teams of one man and one woman', function () {
    [$session] = mixedBoard('MMMWWW');

    $staged = stagedOf($session);

    expect($staged)->toHaveCount(1)
        ->and(teamGenders($staged[0]))->toBe([['man', 'woman'], ['man', 'woman']]);
});

test('the slot waits when a gender is short, and players with no gender are never placed', function () {
    [$session, $players] = mixedBoard('MMMMWN');
    expect(stagedOf($session))->toHaveCount(0);

    $w = Player::factory()->for($session->club)->manual(3)->create(['gender' => Gender::Woman]);
    app(CheckInService::class)->checkIn($session, $w);

    $staged = stagedOf($session);
    expect($staged)->toHaveCount(1)
        ->and(matchIds($staged[0]))->not->toContain($players[5]->id);
});

test('setting a waiting player gender stages a match straight away', function () {
    [$session, $players] = mixedBoard('MMWN');
    expect(stagedOf($session))->toHaveCount(0);

    app(CheckInService::class)->setGender($session, $players[3], 'woman');

    expect(stagedOf($session))->toHaveCount(1)
        ->and(matchIds(stagedOf($session)[0]))->toContain($players[3]->id);
});

test('changing or clearing the gender of a staged player voids and re-stages the match', function () {
    [$session, $players] = mixedBoard('MMWWWW');
    $first = stagedOf($session)[0];
    $man = genderedPlayerOf($first, 'man');

    // Man becomes woman: only one man is left, so the voided match cannot be re-staged.
    app(CheckInService::class)->setGender($session, $man, 'woman');
    expect($first->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(0);

    // Back to man: staged again as a valid mixed match.
    app(CheckInService::class)->setGender($session, $man, 'man');
    $second = stagedOf($session)[0];
    expect(teamGenders($second))->toBe([['man', 'woman'], ['man', 'woman']]);

    // Clearing a staged player's gender voids that match; no re-stage can include them.
    $woman = genderedPlayerOf($second, 'woman');
    app(CheckInService::class)->setGender($session, $woman, null);
    expect($second->fresh()->status)->toBe(MatchStatus::Void);
    foreach (stagedOf($session) as $m) {
        expect(matchIds($m))->not->toContain($woman->id);
    }
});

test('swap only accepts a player of the same gender', function () {
    [$session, $players] = mixedBoard('MMWWMWN');
    $match = stagedOf($session)[0];
    $out = genderedPlayerOf($match, 'man');

    foreach ([$players[5], $players[6]] as $bad) {
        try {
            app(MatchService::class)->swap($session, $match, $out, $bad);
            test()->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('player')
                ->and($e->errors()['player'][0])->toContain('same gender');
        }
    }

    app(MatchService::class)->swap($session, $match, $out, $players[4]);
    expect(matchIds($match->fresh()))->toContain($players[4]->id)->not->toContain($out->id);
});

test('remove fills the slot with the same gender', function () {
    [$session] = mixedBoard('MMWWMWMW');
    $match = stagedOf($session)[0];
    $removed = genderedPlayerOf($match, 'woman');

    app(MatchService::class)->remove($session, $match, $removed);

    expect(teamGenders($match->fresh()))->toBe([['man', 'woman'], ['man', 'woman']])
        ->and(matchIds($match->fresh()))->not->toContain($removed->id);
});

test('remove is blocked when no one of that gender is waiting', function () {
    [$session] = mixedBoard('MMWWMM');
    $match = stagedOf($session)[0];
    $woman = genderedPlayerOf($match, 'woman');

    expect(fn () => app(MatchService::class)->remove($session, $match, $woman))->toThrow(ValidationException::class)
        ->and(matchIds($match->fresh()))->toContain($woman->id);
});

test('re-roll stages a different mixed match', function () {
    [$session] = mixedBoard('MMMMWWWW');
    $match = stagedOf($session)[0];
    $before = matchIds($match);

    app(MatchService::class)->reroll($session, $match);

    $staged = stagedOf($session);
    expect($staged)->toHaveCount(1)
        ->and(matchIds($staged[0]))->not->toBe($before)
        ->and(teamGenders($staged[0]))->toBe([['man', 'woman'], ['man', 'woman']]);
});

test('estimates use the rank within the player gender, and no gender gets none', function () {
    // Staged: the first 2 men and 2 women. Waiting: 4 men, 4 women, 1 with no gender.
    [$session] = mixedBoard('MMWWMMMMWWWWN', ['courts' => 1]);

    $waiting = collect(app(SessionBoard::class)->waiting($session));
    expect($waiting)->toHaveCount(9);

    $men = $waiting->where('gender', 'man')->pluck('estimate_minutes')->values()->all();
    $women = $waiting->where('gender', 'woman')->pluck('estimate_minutes')->values()->all();
    $none = $waiting->whereNull('gender')->values();

    // Match index = 1 staged + floor(rank / 2): men ranks 0 and 1 share a match, rank 2 is the next.
    expect($men[0])->toBe($men[1])
        ->and($men[2])->toBeGreaterThan($men[1])
        ->and($women[0])->toBe($men[0])
        ->and($none)->toHaveCount(1)
        ->and($none[0]['estimate_minutes'])->toBeNull();
});

test('balanced estimates and read model are unchanged', function () {
    [$session] = board(13, ['courts' => 1]);
    $board = app(SessionBoard::class);

    $rows = $board->waiting($session->fresh());

    expect($rows[0])->toHaveKeys(['estimate_minutes', 'gender', 'needs_gender'])
        ->and($rows[0]['needs_gender'])->toBeFalse()
        ->and($board->unplaceableCount($session->fresh()))->toBe(0)
        ->and($board->mode($session->fresh()))->toBe('balanced');
});

test('the board flags waiting players with no gender and counts them', function () {
    [$session] = mixedBoard('MWNN');
    $board = app(SessionBoard::class);

    $rows = collect($board->waiting($session));

    expect($board->mode($session))->toBe('mixed')
        ->and($board->unplaceableCount($session))->toBe(2)
        ->and($rows->where('needs_gender', true))->toHaveCount(2)
        ->and($rows->firstWhere('gender', 'man')['needs_gender'])->toBeFalse();
});

test('the public view exposes the mode and never a gender', function () {
    [$session] = mixedBoard('MMWWMWN');
    $snap = app(PublicSessionView::class)->snapshot($session);

    expect($snap['mode'])->toBe('mixed')
        ->and(json_encode($snap))->not->toContain('gender')
        ->and(array_keys($snap['waiting'][0]))->toBe(['position', 'id', 'name', 'wins', 'estimate_minutes', 'group', 'group_position']);
});

test('finishing a match keeps staging and playing mixed matches only', function () {
    [$session] = mixedBoard('MMMMWWWW', ['auto_fill' => true]);
    $playing = GameMatch::query()->where('play_session_id', $session->id)->where('status', MatchStatus::Playing->value)->firstOrFail();

    app(MatchService::class)->finish($session, $playing, 11, 5);

    $open = GameMatch::query()->where('play_session_id', $session->id)->whereIn('status', [MatchStatus::Staged->value, MatchStatus::Playing->value])->get();
    expect($open)->not->toBeEmpty();
    foreach ($open as $m) {
        expect(teamGenders($m))->toBe([['man', 'woman'], ['man', 'woman']]);
    }
    expect(entryOf($session, Player::findOrFail($playing->matchPlayers()->first()->player_id))->status)->toBeIn([SessionPlayerStatus::Waiting, SessionPlayerStatus::Playing]);
});

test('changing gender through the player service voids the staged match in a live mixed session and re-stages', function () {
    [$session] = mixedBoard('MMWWWW');
    $first = stagedOf($session)[0];
    $man = genderedPlayerOf($first, 'man');

    app(PlayerService::class)->update($man, ['gender' => 'woman']);

    expect($first->fresh()->status)->toBe(MatchStatus::Void)
        ->and(stagedOf($session))->toHaveCount(0);

    app(PlayerService::class)->update($man->fresh(), ['gender' => 'man']);
    expect(teamGenders(stagedOf($session)[0]))->toBe([['man', 'woman'], ['man', 'woman']]);

    // An update that keeps the gender leaves the staged match alone.
    $staged = stagedOf($session)[0];
    app(PlayerService::class)->update($man->fresh(), ['name' => 'Renamed']);
    expect($staged->fresh()->status)->toBe(MatchStatus::Staged);
});

test('staff match rows carry gender, and gender never reaches the public courts or Up Next', function () {
    [$session] = mixedBoard('MMMMWWWWMW', ['auto_fill' => true]);
    $board = app(SessionBoard::class);

    $staffRows = [...$board->staged($session), ...array_filter(array_column($board->courts($session), 'match'))];
    expect($staffRows)->not->toBeEmpty();
    foreach ($staffRows as $row) {
        foreach ([...$row['teams']['A'], ...$row['teams']['B']] as $p) {
            expect($p['gender'])->toBeIn(['man', 'woman']);
        }
    }

    $snap = app(PublicSessionView::class)->snapshot($session);
    expect(array_filter(array_column($snap['courts'], 'match')))->not->toBeEmpty()
        ->and(json_encode($snap['courts']))->not->toContain('gender')
        ->and(json_encode($snap['up_next']))->not->toContain('gender')
        ->and(json_encode($snap))->not->toContain('gender');
});
