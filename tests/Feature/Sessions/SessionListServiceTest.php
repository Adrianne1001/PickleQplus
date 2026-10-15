<?php

use App\Enums\LateArrivalPolicy;
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
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\PlayerService;
use App\Services\PlaySessionService;
use App\Services\SessionListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

function listSvc(): SessionListService
{
    return app(SessionListService::class);
}

/** @return array<string, list<string>> */
function listErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function listIds(PlaySession $session): array
{
    return SessionListPlayer::query()->where('play_session_id', $session->id)->orderBy('id')->pluck('player_id')->map(fn ($id) => (int) $id)->all();
}

// --- add ---

test('add lists active club players, is a no-op for players already listed, and records source and actor', function () {
    $session = PlaySession::factory()->create();
    $user = User::factory()->create();
    $a = Player::factory()->for($session->club)->create();
    $b = Player::factory()->for($session->club)->create();

    expect(listSvc()->add($session, [$a->id, $b->id], $user, ListSource::Paste))->toBe(2)
        ->and(listSvc()->add($session, [$a->id], $user))->toBe(0)
        ->and(listSvc()->add($session, [], $user))->toBe(0)
        ->and(listIds($session))->toBe([$a->id, $b->id]);

    $row = SessionListPlayer::query()->where('player_id', $a->id)->firstOrFail();
    expect($row->source)->toBe(ListSource::Paste)->and($row->added_by_user_id)->toBe($user->id)
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('add refuses a player from another club on every call and lists nothing', function () {
    $session = PlaySession::factory()->create();
    $mine = Player::factory()->for($session->club)->create();
    $foreign = Player::factory()->create();

    $errors = listErrors(fn () => listSvc()->add($session, [$mine->id, $foreign->id]));

    expect($errors)->toHaveKey('players')
        ->and($errors['players'][0])->not->toContain($foreign->name)
        ->and(SessionListPlayer::query()->count())->toBe(0);
});

test('add refuses deactivated players, naming them, and ended sessions', function () {
    $session = PlaySession::factory()->create();
    $off = Player::factory()->for($session->club)->inactive()->create(['name' => 'Gone Gary']);

    expect(listErrors(fn () => listSvc()->add($session, [$off->id]))['players'][0])->toContain('Gone Gary');

    $ended = PlaySession::factory()->ended()->for($session->club)->create();
    $ok = Player::factory()->for($session->club)->create();
    expect(listErrors(fn () => listSvc()->add($ended, [$ok->id])))->toHaveKey('session');
});

test('add fires the change event once and not for a no-op', function () {
    $session = PlaySession::factory()->create();
    $a = Player::factory()->for($session->club)->create();
    Event::fake([PlaySessionChanged::class]);

    listSvc()->add($session, [$a->id]);
    Event::assertDispatchedTimes(PlaySessionChanged::class, 1);

    listSvc()->add($session, [$a->id]);
    Event::assertDispatchedTimes(PlaySessionChanged::class, 1);
});

test('the same player can be listed in two sessions', function () {
    $club = Club::factory()->create();
    $one = PlaySession::factory()->for($club)->create();
    $two = PlaySession::factory()->for($club)->create();
    $p = Player::factory()->for($club)->create();

    listSvc()->add($one, [$p->id]);
    listSvc()->add($two, [$p->id]);

    expect(SessionListPlayer::query()->where('player_id', $p->id)->count())->toBe(2);
});

// --- checking in keeps everyone listed ---

test('checking in through CheckInService lists the player, and removing the check-in keeps the entry', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = PlaySession::factory()->for($club)->live()->create();
    $p = Player::factory()->for($club)->create();

    app(CheckInService::class)->checkIn($session, $p);
    expect(listIds($session))->toBe([$p->id]);

    app(CheckInService::class)->removeCheckIn($session, $p, $user);

    expect(SessionPlayer::query()->count())->toBe(0)
        ->and(listIds($session))->toBe([$p->id])
        ->and(listSvc()->notYetHere($session)->pluck('player_id')->all())->toBe([$p->id]);
});

test('checking in an already listed player does not duplicate the entry', function () {
    $session = PlaySession::factory()->live()->create();
    $p = Player::factory()->for($session->club)->create();
    listSvc()->add($session, [$p->id], null, ListSource::Reclub);

    app(CheckInService::class)->checkIn($session, $p);
    app(CheckInService::class)->checkIn($session, $p);

    expect(SessionListPlayer::query()->where('player_id', $p->id)->count())->toBe(1)
        ->and(SessionListPlayer::query()->where('player_id', $p->id)->value('source'))->toBe(ListSource::Reclub);
});

test('a player who cannot be checked in is not listed by the attempt', function () {
    $session = PlaySession::factory()->ended()->create();
    $p = Player::factory()->for($session->club)->create();
    $off = Player::factory()->for($session->club)->inactive()->create();
    $live = PlaySession::factory()->live()->for($session->club)->create();

    expect(listErrors(fn () => app(CheckInService::class)->checkIn($session, $p)))->toHaveKey('session')
        ->and(listErrors(fn () => app(CheckInService::class)->checkIn($live, $off)))->toHaveKey('player')
        ->and(listErrors(fn () => app(CheckInService::class)->checkIn($live, Player::factory()->create())))->toHaveKey('player')
        ->and(SessionListPlayer::query()->count())->toBe(0);
});

// --- remove ---

test('remove takes a player off the list only while they have no session_players row', function () {
    $session = PlaySession::factory()->live()->create();
    $here = Player::factory()->for($session->club)->create();
    $away = Player::factory()->for($session->club)->create();
    listSvc()->add($session, [$here->id, $away->id]);
    listSvc()->checkIn($session, $here->id);

    expect(listErrors(fn () => listSvc()->remove($session, $here->id)))->toHaveKey('player');

    // Left players stay listed too.
    app(CheckInService::class)->checkOut($session, $here);
    expect(listErrors(fn () => listSvc()->remove($session, $here->id)))->toHaveKey('player');

    Event::fake([PlaySessionChanged::class]);
    listSvc()->remove($session, $away->id);

    expect(listIds($session))->toBe([$here->id]);
    Event::assertDispatched(PlaySessionChanged::class);
});

test('remove refuses a foreign club player, an unlisted player and an ended session', function () {
    $session = PlaySession::factory()->create();
    $foreign = Player::factory()->create();
    $unlisted = Player::factory()->for($session->club)->create();

    expect(listErrors(fn () => listSvc()->remove($session, $foreign->id)))->toHaveKey('player')
        ->and(listErrors(fn () => listSvc()->remove($session, $unlisted->id)))->toHaveKey('player');

    $ended = PlaySession::factory()->ended()->for($session->club)->create();
    expect(listErrors(fn () => listSvc()->remove($ended, $unlisted->id)))->toHaveKey('session');
});

// --- set skill ---

test('set skill sets the stars and clears the pending flag, keeping the rating source manual', function () {
    $session = PlaySession::factory()->create();
    $p = listSvc()->addNewPlayer($session, ['name' => 'Pending Pat']);
    expect($p->stars_pending)->toBeTrue();

    Event::fake([PlaySessionChanged::class]);
    $updated = listSvc()->setSkill($session, $p->id, '5');

    expect($updated->stars)->toBe(5)->and($updated->stars_pending)->toBeFalse()
        ->and($p->fresh()->rating_source)->toBe(RatingSource::Manual)
        ->and($p->fresh()->stars_pending)->toBeFalse();
    Event::assertDispatched(PlaySessionChanged::class);
});

test('set skill validates the stars, the club, the list and that the skill is pending', function () {
    $session = PlaySession::factory()->create();
    $pending = listSvc()->addNewPlayer($session, ['name' => 'Pending Pat']);
    $set = Player::factory()->for($session->club)->manual(2)->create();
    listSvc()->add($session, [$set->id]);
    $unlisted = Player::factory()->for($session->club)->create();
    $unlisted->forceFill(['stars_pending' => true])->save();
    $foreign = Player::factory()->manual(2)->create();
    $foreign->forceFill(['stars_pending' => true])->save();

    expect(listErrors(fn () => listSvc()->setSkill($session, $pending->id, 0)))->toHaveKey('stars')
        ->and(listErrors(fn () => listSvc()->setSkill($session, $pending->id, 7)))->toHaveKey('stars')
        ->and(listErrors(fn () => listSvc()->setSkill($session, $pending->id, 'x')))->toHaveKey('stars')
        ->and(listErrors(fn () => listSvc()->setSkill($session, $set->id, 4)))->toHaveKey('player')
        ->and(listErrors(fn () => listSvc()->setSkill($session, $unlisted->id, 4)))->toHaveKey('player')
        ->and(listErrors(fn () => listSvc()->setSkill($session, $foreign->id, 4)))->toHaveKey('player')
        ->and($pending->fresh()->stars_pending)->toBeTrue()
        ->and($foreign->fresh()->stars)->toBe(2);
});

test('saving stars on the player form path clears the pending flag, and an unrelated edit keeps it', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create();
    $p = listSvc()->addNewPlayer($session, ['name' => 'Pending Pat']);

    app(PlayerService::class)->update($p, ['name' => 'Pending Patricia']);
    expect($p->fresh()->stars_pending)->toBeTrue();

    app(PlayerService::class)->update($p->fresh(), ['stars' => 4, 'rating_source' => 'manual']);
    expect($p->fresh()->stars_pending)->toBeFalse()->and($p->fresh()->stars)->toBe(4);

    $q = listSvc()->addNewPlayer($session, ['name' => 'Rated Rae']);
    app(PlayerService::class)->update($q->fresh(), ['dupr_rating' => 4.2]);
    expect($q->fresh()->stars_pending)->toBeFalse()->and($q->fresh()->rating_source)->toBe(RatingSource::Dupr);
});

test('a player created through the service never starts pending unless asked', function () {
    $club = Club::factory()->create();
    $plain = app(PlayerService::class)->create($club, ['name' => 'Plain', 'stars' => 3]);
    $flagged = app(PlayerService::class)->create($club, ['name' => 'Flagged', 'stars' => 3, 'stars_pending' => true]);
    $rated = app(PlayerService::class)->create($club, ['name' => 'Rated', 'dupr_rating' => 4.0, 'stars_pending' => true]);

    expect($plain->fresh()->stars_pending)->toBeFalse()
        ->and($flagged->fresh()->stars_pending)->toBeTrue()
        ->and($rated->fresh()->stars_pending)->toBeFalse();
});

// --- check in one ---

test('check in goes through CheckInService, with late-arrival credit, and needs the player to be listed', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $other = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $other->id, 'games_played' => 5, 'status' => SessionPlayerStatus::Waiting]);
    $late = Player::factory()->for($club)->create();
    $unlisted = Player::factory()->for($club)->create();
    listSvc()->add($session, [$late->id]);

    $entry = listSvc()->checkIn($session, $late->id);

    expect($entry->games_credit)->toBe(5)->and($entry->status)->toBe(SessionPlayerStatus::Waiting)
        ->and(listErrors(fn () => listSvc()->checkIn($session, $unlisted->id)))->toHaveKey('player')
        ->and(listErrors(fn () => listSvc()->checkIn($session, Player::factory()->create()->id)))->toHaveKey('player')
        ->and(SessionPlayer::query()->where('player_id', $unlisted->id)->exists())->toBeFalse();
});

test('check in refuses a listed player who has since been deactivated', function () {
    $session = PlaySession::factory()->live()->create();
    $p = Player::factory()->for($session->club)->create();
    listSvc()->add($session, [$p->id]);
    $p->forceFill(['active' => false])->save();

    expect(listErrors(fn () => listSvc()->checkIn($session, $p->id)))->toHaveKey('player')
        ->and(SessionPlayer::query()->count())->toBe(0);
});

// --- check in all ---

test('check in all is best effort and names who was skipped and why', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create();
    $elsewhere = PlaySession::factory()->for($club)->live()->create();
    $ok = Player::factory()->for($club)->count(3)->create();
    $busy = Player::factory()->for($club)->create(['name' => 'Busy Ana']);
    SessionPlayer::factory()->create(['play_session_id' => $elsewhere->id, 'player_id' => $busy->id, 'status' => SessionPlayerStatus::Waiting]);
    $already = Player::factory()->for($club)->create();
    app(CheckInService::class)->checkIn($session, $already);
    listSvc()->add($session, [...$ok->pluck('id')->all(), $busy->id]);
    Event::fake([PlaySessionChanged::class]);

    $result = listSvc()->checkInAll($session);

    expect($result['checked_in'])->toBe(3)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]['name'])->toBe('Busy Ana')
        ->and($result['skipped'][0]['reason'])->toContain('another live session')
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->count())->toBe(4)
        ->and(SessionPlayer::query()->where('play_session_id', $session->id)->where('player_id', $busy->id)->exists())->toBeFalse();
    Event::assertDispatched(PlaySessionChanged::class);

    // Nothing left to do (the busy player is still Not Yet Here): the same player is skipped again.
    expect(listSvc()->checkInAll($session)['skipped'])->toHaveCount(1);
});

test('check in all with nobody to check in does nothing', function () {
    $session = PlaySession::factory()->live()->create();

    expect(listSvc()->checkInAll($session))->toBe(['checked_in' => 0, 'skipped' => []]);
});

test('check in all refuses an ended session and only touches this sessions list', function () {
    $club = Club::factory()->create();
    $ended = PlaySession::factory()->ended()->for($club)->create();
    $other = PlaySession::factory()->for($club)->create();
    $p = Player::factory()->for($club)->create();
    listSvc()->add($other, [$p->id]);

    expect(listErrors(fn () => listSvc()->checkInAll($ended)))->toHaveKey('session');

    $session = PlaySession::factory()->for($club)->create();
    expect(listSvc()->checkInAll($session)['checked_in'])->toBe(0)
        ->and(SessionPlayer::query()->count())->toBe(0);
});

test('check in all applies the late-arrival credit in order', function () {
    $club = Club::factory()->create(['late_arrival_policy' => LateArrivalPolicy::Minimum]);
    $session = PlaySession::factory()->for($club)->live()->create();
    $regular = Player::factory()->for($club)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $regular->id, 'games_played' => 2, 'status' => SessionPlayerStatus::Waiting]);
    $late = Player::factory()->for($club)->count(2)->create();
    listSvc()->add($session, $late->pluck('id')->all());

    listSvc()->checkInAll($session);

    expect(SessionPlayer::query()->whereIn('player_id', $late->pluck('id'))->pluck('games_credit')->all())->toBe([2, 2]);
});

// --- queries ---

test('not yet here lists the listed players without a session row, in listing order, and counts follow', function () {
    $session = PlaySession::factory()->live()->create();
    [$a, $b, $c, $d] = Player::factory()->for($session->club)->count(4)->create()->all();
    listSvc()->add($session, [$c->id]);
    listSvc()->add($session, [$a->id, $b->id, $d->id]);

    expect(listSvc()->counts($session))->toBe(['checked_in' => 0, 'listed' => 4, 'not_yet_here' => 4]);

    listSvc()->checkIn($session, $a->id);
    listSvc()->checkIn($session, $b->id);
    app(CheckInService::class)->checkOut($session, $b);

    expect(listSvc()->notYetHere($session)->pluck('player_id')->all())->toBe([$c->id, $d->id])
        ->and(listSvc()->notYetHere($session)->first()->player)->toBeInstanceOf(Player::class)
        // N counts listed players whose status is not left; M is the list size.
        ->and(listSvc()->counts($session))->toBe(['checked_in' => 1, 'listed' => 4, 'not_yet_here' => 2]);
});

test('the search skips listed players, inactive players and other clubs', function () {
    $session = PlaySession::factory()->create();
    $listed = Player::factory()->for($session->club)->create(['name' => 'Sam Listed']);
    Player::factory()->for($session->club)->create(['name' => 'Sam Free']);
    Player::factory()->for($session->club)->inactive()->create(['name' => 'Sam Off']);
    Player::factory()->create(['name' => 'Sam Elsewhere']);
    listSvc()->add($session, [$listed->id]);

    expect(listSvc()->search($session, 'sam')->pluck('name')->all())->toBe(['Sam Free'])
        ->and(listSvc()->search($session, 'sam', [Player::query()->where('name', 'Sam Free')->value('id')])->all())->toBe([])
        ->and(listSvc()->search($session, ' ')->all())->toBe([])
        ->and(listSvc()->search($session, '%')->all())->toBe([]);
});

// --- password ---

test('the password is trimmed, 4 to 32 characters, stored encrypted and can be cleared', function () {
    $session = PlaySession::factory()->create();
    Event::fake([PlaySessionChanged::class]);

    listSvc()->setPassword($session, '  Court Seven ');

    expect($session->fresh()->checkin_password)->toBe('Court Seven')
        ->and(DB::table('play_sessions')->where('id', $session->id)->value('checkin_password'))->not->toContain('Court')
        ->and($session->fresh()->toArray())->not->toHaveKey('checkin_password');
    Event::assertDispatched(PlaySessionChanged::class);

    expect(listErrors(fn () => listSvc()->setPassword($session, 'abc')))->toHaveKey('password')
        ->and(listErrors(fn () => listSvc()->setPassword($session, str_repeat('x', 33))))->toHaveKey('password')
        ->and($session->fresh()->checkin_password)->toBe('Court Seven');

    listSvc()->setPassword($session, str_repeat('x', 32));
    listSvc()->setPassword($session, 'abcd');
    expect($session->fresh()->checkin_password)->toBe('abcd');

    listSvc()->setPassword($session, '   ');
    expect($session->fresh()->checkin_password)->toBeNull();

    listSvc()->setPassword($session, 'again');
    listSvc()->clearPassword($session);
    expect($session->fresh()->checkin_password)->toBeNull();
});

test('changing the password does not affect anyone already checked in, and regenerating the QR keeps it', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'Club']);
    $club = $user->clubs()->firstOrFail();
    $session = PlaySession::factory()->for($club)->live()->create();
    $p = Player::factory()->for($club)->create();
    listSvc()->add($session, [$p->id]);
    listSvc()->checkIn($session, $p->id);

    listSvc()->setPassword($session, 'secret1');
    app(PlaySessionService::class)->regenerateCheckinToken($session, $user);

    expect($session->fresh()->checkin_password)->toBe('secret1')
        ->and(SessionPlayer::query()->where('player_id', $p->id)->firstOrFail()->status)->toBe(SessionPlayerStatus::Waiting);
});

test('the password is refused on an ended session', function () {
    $session = PlaySession::factory()->ended()->create();

    expect(listErrors(fn () => listSvc()->setPassword($session, 'secret1')))->toHaveKey('session');
});

test('the password match is trimmed and case-insensitive, and open when none is set', function () {
    $session = PlaySession::factory()->create();
    expect($session->checkinPasswordMatches(null))->toBeTrue()->and($session->checkinPasswordMatches('anything'))->toBeTrue();

    listSvc()->setPassword($session, 'Court7');
    $session = $session->fresh();

    expect($session->checkinPasswordMatches(' cOURT7 '))->toBeTrue()
        ->and($session->checkinPasswordMatches('Court8'))->toBeFalse()
        ->and($session->checkinPasswordMatches(''))->toBeFalse()
        ->and($session->checkinPasswordMatches(null))->toBeFalse();
});

// --- the backfill ---

test('the migration backfill lists everyone already checked in', function () {
    $session = PlaySession::factory()->live()->create();
    $p = Player::factory()->for($session->club)->create();
    DB::table('session_players')->insert([
        'play_session_id' => $session->id, 'player_id' => $p->id, 'status' => 'waiting',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('session_list_players')->delete();

    DB::table('session_list_players')->insertUsing(
        ['play_session_id', 'player_id', 'source', 'created_at', 'updated_at'],
        DB::table('session_players')->select(['play_session_id', 'player_id', DB::raw("'manual'"), 'created_at', 'updated_at']),
    );

    expect(listIds($session))->toBe([$p->id]);
});
