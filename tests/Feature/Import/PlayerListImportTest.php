<?php

use App\Enums\Gender;
use App\Enums\ListSource;
use App\Enums\RatingSource;
use App\Enums\SessionPlayerStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\PlayerListImportService;
use App\Services\ReclubFetcher;
use App\Services\SessionFormService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function imp(): PlayerListImportService
{
    return app(PlayerListImportService::class);
}

/** @return list<array<string, mixed>> */
function impRows(Club $club, ?PlaySession $session, string $text, array $alsoListed = []): array
{
    return imp()->reviewPaste($club, $session, $text, $alsoListed);
}

function impStatuses(array $rows): array
{
    return array_map(fn (array $r): string => $r['status'], $rows);
}

/** @return array<string, list<string>> */
function impErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

// --- review: matching and outcomes ---

test('rows are matched by DUPR ID first, then by case-insensitive name, and unmatched rows are new', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes', 'dupr_id' => 'AAA111']);
    $ben = Player::factory()->for($club)->create(['name' => 'Ben Cruz']);

    $rows = impRows($club, $session, "Ana Reyes | duprId=aaa111\nBEN  cruz\nCara Lim | rating=4.2");

    expect(impStatuses($rows))->toBe(['existing', 'existing', 'new'])
        ->and($rows[0]['player_id'])->toBe($ana->id)->and($rows[0]['player_name'])->toBe('Ana Reyes')
        ->and($rows[1]['player_id'])->toBe($ben->id)->and($rows[1]['name'])->toBe('BEN cruz')
        ->and($rows[2]['player_id'])->toBeNull()
        ->and($rows[2]['rating'])->toBe('4.2')
        ->and($rows[0]['source'])->toBe('paste')->and($rows[0]['line'])->toBe(1)->and($rows[2]['line'])->toBe(3);
});

test('a row for a player already on the list is reported and skipped', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes']);
    $ben = Player::factory()->for($club)->create(['name' => 'Ben Cruz']);
    listPlayers($session, $ana);

    $rows = impRows($club, $session, "Ana Reyes\nBen Cruz", [$ben->id]);

    expect(impStatuses($rows))->toBe(['listed', 'listed'])
        ->and($rows[0]['message'])->toContain('Already on the list');
});

test('a draft form with no session yet has no list to compare with', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'Ana Reyes']);

    expect(impStatuses(impRows($club, null, 'Ana Reyes')))->toBe(['existing']);
});

test('error rows: bad DUPR ID, bad rating, long name, empty name', function () {
    $session = PlaySession::factory()->create();
    $long = str_repeat('x', 121);

    $rows = impRows($session->club, $session, "Ana | duprId=12\nBen | rating=abc\nCara | rating=9.5\nDan | rating=4.12345\n{$long}\n-\nEli | rating=4.5 | duprId=ABC123");

    expect(impStatuses($rows))->toBe(['error', 'error', 'error', 'error', 'error', 'error', 'new'])
        ->and($rows[0]['message'])->toContain('DUPR ID')
        ->and($rows[1]['message'])->toContain('rating')
        ->and($rows[2]['message'])->toContain('between')
        ->and($rows[3]['message'])->toContain('decimals')
        ->and($rows[4]['message'])->toContain('120')
        ->and($rows[5]['message'])->toContain('required');
});

test('error rows: a name repeated in the import (case-insensitive) errors the later row', function () {
    $session = PlaySession::factory()->create();

    $rows = impRows($session->club, $session, "Ana Reyes\nBen\nana  REYES");

    expect(impStatuses($rows))->toBe(['new', 'new', 'error'])
        ->and($rows[2]['message'])->toContain('repeated');
});

test('error rows: a name that matches a player with a different DUPR ID', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->create(['name' => 'Ana Reyes', 'dupr_id' => 'AAA111']);

    $rows = impRows($session->club, $session, 'Ana Reyes | duprId=BBB222');

    expect($rows[0]['status'])->toBe('error')->and($rows[0]['message'])->toContain('different DUPR ID');
});

test('a name match without a stored DUPR ID is just an existing player, even if the row has one', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana Reyes']);

    $rows = impRows($session->club, $session, 'Ana Reyes | duprId=BBB222 | rating=5.5');

    expect($rows[0]['status'])->toBe('existing')->and($rows[0]['player_id'])->toBe($ana->id);
});

test('error rows: a DUPR ID that belongs to another name', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->create(['name' => 'Ana Reyes', 'dupr_id' => 'AAA111']);

    $rows = impRows($session->club, $session, 'Somebody Else | duprId=AAA111');

    expect($rows[0]['status'])->toBe('error')->and($rows[0]['message'])->toContain('Ana Reyes');
});

test('error rows: the same DUPR ID twice in the import', function () {
    $session = PlaySession::factory()->create();

    $rows = impRows($session->club, $session, "Ana | duprId=AAA111\nBen | duprId=aaa111");

    expect(impStatuses($rows))->toBe(['new', 'error']);
});

test('error rows: a deactivated player', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->inactive()->create(['name' => 'Old Timer']);

    $rows = impRows($session->club, $session, 'old timer');

    expect($rows[0]['status'])->toBe('error')->and($rows[0]['message'])->toContain('Reactivate');
});

test('matching never looks outside the club', function () {
    $session = PlaySession::factory()->create();
    $foreign = Player::factory()->create(['name' => 'Ana Reyes', 'dupr_id' => 'AAA111']);

    $rows = impRows($session->club, $session, 'Ana Reyes | duprId=AAA111');

    expect($rows[0]['status'])->toBe('new')->and($rows[0]['player_id'])->toBeNull()->and($foreign->fresh()->club_id)->not->toBe($session->club_id);
});

test('paste limits and warnings come through', function () {
    $session = PlaySession::factory()->create();

    expect(impErrors(fn () => imp()->reviewPaste($session->club, $session, '')))->toHaveKey('paste')
        ->and(impErrors(fn () => imp()->reviewPaste($session->club, $session, str_repeat("A\n", 201))))->toHaveKey('paste');

    $rows = impRows($session->club, $session, 'Ana | color=red');
    expect($rows[0]['warnings'])->toHaveCount(1)->and($rows[0]['status'])->toBe('new');
});

test('a single edited row is re-matched in the context of the other rows', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana Reyes']);
    $rows = impRows($session->club, $session, "Ana Reyez\nBen");

    expect($rows[0]['status'])->toBe('new');

    $fixed = imp()->reviewRow($session->club, $session, [...$rows[0], 'name' => 'ana reyes'], [$rows[1]]);
    expect($fixed['status'])->toBe('existing')->and($fixed['player_id'])->toBe($ana->id);

    $dup = imp()->reviewRow($session->club, $session, [...$rows[0], 'name' => 'BEN'], [$rows[1]]);
    expect($dup['status'])->toBe('error')->and($dup['message'])->toContain('repeated');

    $bad = imp()->reviewRow($session->club, $session, [...$rows[0], 'rating' => '99'], [$rows[1]]);
    expect($bad['status'])->toBe('error');
});

test('review only trusts the editable fields, not the status a client sends back', function () {
    $session = PlaySession::factory()->create();
    $foreign = Player::factory()->create(['name' => 'Foreign Fred']);
    $forged = ['name' => 'Honest Hal', 'rating' => '', 'dupr_id' => '', 'source' => 'paste', 'status' => 'existing', 'player_id' => $foreign->id, 'line' => 1];

    $row = imp()->review($session->club, $session, [$forged])[0];

    expect($row['status'])->toBe('new')->and($row['player_id'])->toBeNull();
});

// --- confirm ---

test('confirm lists existing players, creates new ones and skips error and already listed rows', function () {
    $session = PlaySession::factory()->live()->create();
    $club = $session->club;
    $user = User::factory()->create();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes']);
    $listed = Player::factory()->for($club)->create(['name' => 'Already Al']);
    listPlayers($session, $listed);
    $rows = impRows($club, $session, "Ana Reyes\nAlready Al\nCara Lim | rating=4.2 | duprId=cc33dd\nDan Unrated\nBad Row | rating=zzz");
    Event::fake([PlaySessionChanged::class]);

    $result = imp()->confirm($session, $rows, $user);

    expect($result)->toMatchArray(['listed' => 3, 'created' => 2, 'already_listed' => 1, 'errors' => 1]);
    Event::assertDispatched(PlaySessionChanged::class);

    $cara = Player::query()->where('name', 'Cara Lim')->firstOrFail();
    $dan = Player::query()->where('name', 'Dan Unrated')->firstOrFail();
    expect($cara->rating_source)->toBe(RatingSource::Dupr)
        ->and($cara->dupr_rating)->toBe('4.200')
        ->and($cara->stars)->toBe(5);
    expect($cara->dupr_id)->toBe('CC33DD')
        ->and($cara->stars_pending)->toBeFalse()
        ->and($dan->stars)->toBe(3)->and($dan->stars_pending)->toBeTrue()->and($dan->rating_source)->toBe(RatingSource::Manual)
        ->and(Player::query()->where('name', 'Bad Row')->exists())->toBeFalse()
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->count())->toBe(4)
        ->and(SessionPlayer::query()->count())->toBe(0)
        ->and(SessionListPlayer::query()->where('player_id', $ana->id)->firstOrFail()->added_by_user_id)->toBe($user->id)
        ->and(SessionListPlayer::query()->where('player_id', $ana->id)->firstOrFail()->source)->toBe(ListSource::Paste);
});

test('a rating sets the DUPR bands stars', function () {
    $session = PlaySession::factory()->create();

    imp()->confirm($session, imp()->reviewPaste($session->club, $session, "Low | rating=2.4\nMid | rating=3.7\nHigh | rating=4.9"));

    expect(Player::query()->where('name', 'Low')->value('stars'))->toBe(1)
        ->and(Player::query()->where('name', 'Mid')->value('stars'))->toBe(4)
        ->and(Player::query()->where('name', 'High')->value('stars'))->toBe(6);
});

test('confirm re-validates on the server and ignores forged statuses', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $foreign = Player::factory()->create(['name' => 'Foreign Fred']);
    $off = Player::factory()->for($club)->inactive()->create(['name' => 'Off Olga']);

    $result = imp()->confirm($session, [
        ['name' => 'Foreign Fred', 'status' => 'existing', 'player_id' => $foreign->id, 'source' => 'paste'],
        ['name' => 'Off Olga', 'status' => 'existing', 'player_id' => $off->id, 'source' => 'paste'],
        ['name' => '', 'status' => 'new', 'source' => 'paste'],
        ['name' => 'Fine Fay', 'rating' => '', 'dupr_id' => '', 'source' => 'weird'],
        'not even an array',
        ['name' => ['nested'], 'rating' => ['x']],
    ]);

    // "Foreign Fred" is a brand-new player in this club; the foreign player is untouched.
    expect($result['created'])->toBe(2)->and($result['errors'])->toBe(4)
        ->and(Player::query()->where('club_id', $club->id)->where('name', 'Foreign Fred')->exists())->toBeTrue()
        ->and(SessionListPlayer::query()->where('player_id', $foreign->id)->exists())->toBeFalse()
        ->and(SessionListPlayer::query()->where('player_id', $off->id)->exists())->toBeFalse()
        ->and(SessionListPlayer::query()->where('play_session_id', $session->id)->count())->toBe(2);
});

test('confirm is atomic: a failure after some work leaves nothing behind', function () {
    $session = PlaySession::factory()->create();
    config(['pickleq.guest.max_listed' => 1]);
    $club = $session->club;
    $club->forceFill(['is_guest' => true, 'guest_expires_at' => now()->addDay()])->save();

    $before = Player::query()->count();
    $errors = impErrors(fn () => imp()->confirm($session, [['name' => 'One New', 'source' => 'paste'], ['name' => 'Two New', 'source' => 'paste']]));

    expect($errors)->toHaveKey('players')
        ->and(Player::query()->count())->toBe($before)
        ->and(SessionListPlayer::query()->count())->toBe(0);
});

test('review, confirm and selection refuse more rows than a paste may hold', function () {
    $session = PlaySession::factory()->create();
    $rows = array_map(fn (int $i): array => ['name' => "Player {$i}", 'source' => 'paste'], range(1, 201));

    expect(impErrors(fn () => imp()->review($session->club, $session, $rows)))->toHaveKey('players')
        ->and(impErrors(fn () => imp()->confirm($session, $rows)))->toHaveKey('players')
        ->and(impErrors(fn () => imp()->selection($session->club, $session, $rows)))->toHaveKey('players')
        ->and(Player::query()->count())->toBe(0);

    // Exactly 200 is fine.
    expect(imp()->confirm($session, array_slice($rows, 0, 200))['created'])->toBe(200);
});

test('a player deactivated between the review and the list write is skipped with a note, not fatal', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes']);
    $ben = Player::factory()->for($club)->create(['name' => 'Ben Cruz']);
    $rows = impRows($club, $session, "Ana Reyes\nBen Cruz\nCara New");

    // The deactivation lands after confirm's own re-review but before the list write: simulate it by
    // deactivating right when the first list row is about to be inserted.
    SessionListPlayer::creating(function () use ($ben): void {
        Player::query()->whereKey($ben->id)->update(['active' => false]);
    });
    // The re-review inside confirm already sees Ben as inactive here, so this also covers that path.
    Player::query()->whereKey($ben->id)->update(['active' => false]);

    $result = imp()->confirm($session, $rows);

    expect($result['listed'])->toBe(2)->and($result['created'])->toBe(1)->and($result['errors'])->toBe(1)
        ->and(SessionListPlayer::query()->where('player_id', $ben->id)->exists())->toBeFalse()
        ->and(SessionListPlayer::query()->where('player_id', $ana->id)->exists())->toBeTrue();
});

test('the lock-time filter drops a pick who is inactive when the list is written', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $ben = Player::factory()->for($club)->create(['name' => 'Ben Cruz']);
    $rows = impRows($club, $session, "Ben Cruz\nCara New");

    // Deactivate Ben after the review, exactly when confirm creates the new player (which is after its own review).
    Player::creating(function () use ($ben): void {
        Player::query()->whereKey($ben->id)->update(['active' => false]);
    });

    $result = imp()->confirm($session, $rows);

    expect($result['listed'])->toBe(1)->and($result['created'])->toBe(1)->and($result['errors'])->toBe(1)
        ->and($result['notes'][0])->toContain('Ben Cruz')
        ->and(SessionListPlayer::query()->where('player_id', $ben->id)->exists())->toBeFalse();
});

test('confirm on an ended session is refused and creates nothing', function () {
    $session = PlaySession::factory()->ended()->create();

    expect(impErrors(fn () => imp()->confirm($session, [['name' => 'Late Larry', 'source' => 'paste']])))->toHaveKey('session')
        ->and(Player::query()->count())->toBe(0);
});

test('confirm with only error rows does nothing and fires no event', function () {
    $session = PlaySession::factory()->create();
    Event::fake([PlaySessionChanged::class]);

    $result = imp()->confirm($session, [['name' => 'Bad', 'rating' => 'x', 'source' => 'paste']]);

    expect($result)->toMatchArray(['listed' => 0, 'created' => 0, 'errors' => 1]);
    Event::assertNotDispatched(PlaySessionChanged::class);
});

test('a name taken after the review is skipped with a note, not fatal', function () {
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $rows = impRows($club, $session, "Fresh Fay\nRace Rita");
    // The unique index would catch this; the re-review turns the second row into an existing player instead.
    Player::factory()->for($club)->create(['name' => 'Race Rita']);

    $result = imp()->confirm($session, $rows);

    expect($result['created'])->toBe(1)->and($result['listed'])->toBe(2)->and($result['errors'])->toBe(0)
        ->and(Player::query()->where('club_id', $club->id)->where('name', 'Race Rita')->count())->toBe(1);
});

test('confirming the same list twice lists nobody twice', function () {
    $session = PlaySession::factory()->create();
    $rows = impRows($session->club, $session, "Ana\nBen");

    imp()->confirm($session, $rows);
    $second = imp()->confirm($session, $rows);

    expect($second['listed'])->toBe(0)->and($second['created'])->toBe(0)->and($second['already_listed'])->toBe(2)
        ->and(SessionListPlayer::query()->count())->toBe(2);
});

// --- selection for the session form ---

test('selection splits rows into picks and new players in the shapes the form save takes', function () {
    $club = Club::factory()->create();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes']);
    $rows = impRows($club, null, "Ana Reyes\nCara Lim | rating=4.2 | duprId=cc33dd\nDan\nBad | rating=x");

    $selection = imp()->selection($club, null, $rows);

    expect($selection['player_ids'])->toBe([['id' => $ana->id, 'source' => 'paste', 'gender' => '']])
        ->and($selection['new_players'])->toBe([
            ['name' => 'Cara Lim', 'stars' => null, 'dupr_id' => 'CC33DD', 'dupr_rating' => '4.200', 'gender' => '', 'source' => 'paste'],
            ['name' => 'Dan', 'stars' => null, 'dupr_id' => null, 'dupr_rating' => null, 'gender' => '', 'source' => 'paste'],
        ])
        ->and($selection['errors'])->toBe(1);

    $user = User::factory()->create();
    $session = app(SessionFormService::class)->save($club, null, ['name' => 'From import'], $selection['player_ids'], $selection['new_players'], $user);

    $dan = Player::query()->where('name', 'Dan')->firstOrFail();
    $cara = Player::query()->where('name', 'Cara Lim')->firstOrFail();
    expect(SessionListPlayer::query()->where('play_session_id', $session->id)->count())->toBe(3)
        ->and($dan->stars)->toBe(3)->and($dan->stars_pending)->toBeTrue()
        ->and($cara->rating_source)->toBe(RatingSource::Dupr)->and($cara->stars_pending)->toBeFalse();
});

test('selection counts rows already pending in the form as listed', function () {
    $club = Club::factory()->create();
    $ana = Player::factory()->for($club)->create(['name' => 'Ana Reyes']);

    $selection = imp()->selection($club, null, impRows($club, null, 'Ana Reyes', [$ana->id]), [$ana->id]);

    expect($selection['player_ids'])->toBe([])->and($selection['already_listed'])->toBe(1);
});

// --- Reclub ---

test('a Reclub link is fetched and reviewed with the reclub source', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/reclub-event.html'))]);
    RateLimiter::clear('reclub:fetch:user:99');
    $session = PlaySession::factory()->create();
    $club = $session->club;
    $user = User::factory()->create();
    $existing = Player::factory()->for($club)->create(['name' => 'ben cruz']);

    $rows = imp()->reviewReclub($club, $session, 'https://reclub.co/m/synth1', $user);

    expect(array_column($rows, 'name'))->toBe(['Ana Reyes', 'Ben Cruz', 'Cara Lim', 'Fay Gomez', 'José Álvarez'])
        ->and(impStatuses($rows))->toBe(['new', 'existing', 'new', 'new', 'new'])
        ->and($rows[1]['player_id'])->toBe($existing->id)
        ->and($rows[0]['source'])->toBe('reclub');

    $result = imp()->confirm($session, $rows, $user);

    expect($result['listed'])->toBe(5)->and($result['created'])->toBe(4)
        ->and(SessionListPlayer::query()->where('source', 'reclub')->count())->toBe(5);
});

test('Reclub errors are friendly and the rate limit is per user', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response('<html></html>')]);
    $session = PlaySession::factory()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();
    RateLimiter::clear("reclub:fetch:user:{$a->id}");
    RateLimiter::clear("reclub:fetch:user:{$b->id}");

    expect(impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://reclub.co/m/x', $a))['reclub'][0])->toBe("Couldn't read players from that Reclub link.")
        ->and(impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://evil.example/m/x', $a)))->toHaveKey('reclub');

    for ($i = 0; $i < ReclubFetcher::PER_MINUTE; $i++) {
        impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://reclub.co/m/x', $a));
    }

    expect(impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://reclub.co/m/x', $a))['reclub'][0])->toContain('Too many')
        ->and(impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://reclub.co/m/x', $b))['reclub'][0])->toContain("Couldn't read");
});

test('a Reclub page with no confirmed players is the friendly error', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response('<script id="__NUXT_DATA__">[{"participants":1},[]]</script>')]);
    $session = PlaySession::factory()->create();
    RateLimiter::clear('reclub:fetch:club:'.$session->club_id);

    expect(impErrors(fn () => imp()->reviewReclub($session->club, $session, 'https://www.reclub.co/m/x'))['reclub'][0])->toBe("Couldn't read players from that Reclub link.");
});

// --- sessions and statuses ---

test('imported players are not checked in, even for a live session, until staff check them in', function () {
    $session = PlaySession::factory()->live()->create();

    imp()->confirm($session, impRows($session->club, $session, "Ana\nBen"));

    expect(SessionPlayer::query()->where('status', SessionPlayerStatus::Waiting->value)->count())->toBe(0)
        ->and(SessionListPlayer::query()->count())->toBe(2);
});

// --- gender (P16.8) ---

test('a new player is created with the gender from the paste', function () {
    $session = PlaySession::factory()->create();

    $rows = impRows($session->club, $session, "Ana | gender=F\nBen | sex=male\nCara");

    expect(array_column($rows, 'gender'))->toBe(['woman', 'man', ''])
        ->and(impStatuses($rows))->toBe(['new', 'new', 'new']);

    imp()->confirm($session, $rows);

    $genders = Player::query()->where('club_id', $session->club_id)->pluck('gender', 'name')->map(fn ($g) => $g?->value)->all();
    expect($genders)->toBe(['Ana' => 'woman', 'Ben' => 'man', 'Cara' => null]);
});

test('staff can set the gender of a row in the review and it is re-validated', function () {
    $session = PlaySession::factory()->create();
    $rows = impRows($session->club, $session, "Ana\nBen");
    $rows[0]['gender'] = 'Woman';
    $rows[1]['gender'] = 'M';

    $reviewed = imp()->review($session->club, $session, $rows);

    expect(array_column($reviewed, 'gender'))->toBe(['woman', 'man']);

    imp()->confirm($session, $rows);

    expect(Player::query()->where('name', 'Ana')->value('gender'))->toBe(Gender::Woman)
        ->and(Player::query()->where('name', 'Ben')->value('gender'))->toBe(Gender::Man);
});

test('Reclub rows have no gender until staff set one', function () {
    Http::preventStrayRequests();
    Http::fake(['reclub.co/*' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/reclub-event.html'))]);
    $session = PlaySession::factory()->create();
    RateLimiter::clear('reclub:fetch:club:'.$session->club_id);

    $rows = imp()->reviewReclub($session->club, $session, 'https://reclub.co/m/synth1');

    expect(array_unique(array_column($rows, 'gender')))->toBe(['']);
});

test('an unrecognised gender makes the row an error and nothing is created', function () {
    $session = PlaySession::factory()->create();

    $rows = impRows($session->club, $session, "Ana | gender=robot\nBen | gender=m");

    expect(impStatuses($rows))->toBe(['error', 'new'])
        ->and($rows[0]['message'])->toBe('Gender must be man or woman.')
        ->and($rows[0]['gender'])->toBe('robot');

    $result = imp()->confirm($session, $rows);

    expect($result['created'])->toBe(1)->and($result['errors'])->toBe(1)
        ->and(Player::query()->where('name', 'Ana')->exists())->toBeFalse();
});

test('an existing player with no gender gets the row gender on confirm', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);

    $rows = impRows($session->club, $session, 'Ana | gender=woman');

    expect($rows[0]['status'])->toBe('existing')->and($rows[0]['warnings'])->toBe([]);

    $result = imp()->confirm($session, $rows);

    expect($result['listed'])->toBe(1)->and($ana->fresh()->gender)->toBe(Gender::Woman);
});

test('an existing player with no gender and a row with none stays blank', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);

    imp()->confirm($session, impRows($session->club, $session, 'Ana'));

    expect($ana->fresh()->gender)->toBeNull();
});

test('an existing player with a different gender keeps theirs and the row only warns', function () {
    $session = PlaySession::factory()->create();
    $ben = Player::factory()->for($session->club)->create(['name' => 'Ben', 'gender' => Gender::Man]);

    $rows = impRows($session->club, $session, 'Ben | gender=woman');

    expect($rows[0]['status'])->toBe('existing')
        ->and($rows[0]['message'])->toBeNull()
        ->and($rows[0]['warnings'])->toBe(['Gender differs: this player is already Man, so the gender in this row is ignored.']);

    // Re-reviewing the row from state does not pile the warning up.
    $again = imp()->review($session->club, $session, $rows);
    expect($again[0]['warnings'])->toHaveCount(1);

    $result = imp()->confirm($session, $rows);

    expect($result['listed'])->toBe(1)->and($ben->fresh()->gender)->toBe(Gender::Man);
});

test('the same gender as the player has no warning', function () {
    $session = PlaySession::factory()->create();
    Player::factory()->for($session->club)->create(['name' => 'Ben', 'gender' => Gender::Man]);

    $rows = impRows($session->club, $session, 'Ben | gender=m');

    expect($rows[0]['warnings'])->toBe([])->and($rows[0]['status'])->toBe('existing');
});

test('a gender set after the review is not overwritten on confirm', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);
    $rows = impRows($session->club, $session, 'Ana | gender=woman');

    $ana->forceFill(['gender' => Gender::Man])->save();
    imp()->confirm($session, $rows);

    expect($ana->fresh()->gender)->toBe(Gender::Man);
});

test('an already listed player does not get a gender written', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);
    listPlayers($session, $ana);

    $rows = impRows($session->club, $session, 'Ana | gender=woman');
    imp()->confirm($session, $rows);

    expect($rows[0]['status'])->toBe('listed')->and($ana->fresh()->gender)->toBeNull();
});

test('a forged gender in the row state is rejected on confirm and never written', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);
    $rows = impRows($session->club, $session, "Ana\nBen");
    $rows[0]['gender'] = 'woman; DROP TABLE players';
    $rows[1]['gender'] = ['man'];

    $result = imp()->confirm($session, $rows);

    // Ana is an error row (invalid text). Ben's array value reads as no gender, so he is created without one.
    expect($result['errors'])->toBe(1)->and($result['created'])->toBe(1)->and($result['listed'])->toBe(1)
        ->and($ana->fresh()->gender)->toBeNull()
        ->and(Player::query()->where('name', 'Ben')->value('gender'))->toBeNull();
});

test('a forged status and gender on an existing row cannot overwrite a player gender', function () {
    $session = PlaySession::factory()->create();
    $ben = Player::factory()->for($session->club)->create(['name' => 'Ben', 'gender' => Gender::Man]);
    $rows = impRows($session->club, $session, 'Ben');
    $rows[0]['gender'] = 'woman';
    $rows[0]['status'] = 'existing';
    $rows[0]['player_id'] = $ben->id;

    imp()->confirm($session, $rows);

    expect($ben->fresh()->gender)->toBe(Gender::Man);
});

test('the form selection carries the gender of new players', function () {
    $session = PlaySession::factory()->create();

    $selection = imp()->selection($session->club, $session, impRows($session->club, $session, "Ana | gender=w\nBen"));

    expect(array_column($selection['new_players'], 'gender'))->toBe(['woman', '']);

    app(SessionFormService::class)->save($session->club, $session, [], [], $selection['new_players']);

    expect(Player::query()->where('name', 'Ana')->value('gender'))->toBe(Gender::Woman)
        ->and(Player::query()->where('name', 'Ben')->value('gender'))->toBeNull();
});

test('a player deactivated after the review gets no gender and is skipped as an error', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);
    $rows = impRows($session->club, $session, 'Ana | gender=woman');

    $ana->forceFill(['active' => false])->save();
    $result = imp()->confirm($session, $rows);

    expect($result['errors'])->toBe(1)->and($result['listed'])->toBe(0)
        ->and($ana->fresh()->gender)->toBeNull();
});

test('a player deactivated at the lock-time check gets no gender and is skipped with a note', function () {
    $session = PlaySession::factory()->create();
    $ana = Player::factory()->for($session->club)->create(['name' => 'Ana', 'gender' => null]);
    $rows = impRows($session->club, $session, "Ana | gender=woman\nCara New");

    // After confirm's own re-review, exactly when the new player is created.
    Player::creating(function () use ($ana): void {
        Player::query()->whereKey($ana->id)->update(['active' => false]);
    });

    $result = imp()->confirm($session, $rows);

    expect($result['errors'])->toBe(1)->and($result['created'])->toBe(1)->and($result['listed'])->toBe(1)
        ->and($result['notes'][0])->toContain('Ana')
        ->and($ana->fresh()->gender)->toBeNull()
        ->and(SessionListPlayer::query()->where('player_id', $ana->id)->exists())->toBeFalse();
});
