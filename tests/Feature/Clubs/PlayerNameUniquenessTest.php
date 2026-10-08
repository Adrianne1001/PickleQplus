<?php

use App\Concerns\PlayerValidationRules;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use App\Services\PlayerService;
use App\Services\PublicSessionView;
use App\Services\SelfCheckInService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

function nameRules(Club $club, ?Player $player = null, array $input = []): array
{
    return (new class
    {
        use PlayerValidationRules;

        public function rules(Club $club, ?Player $player, array $input): array
        {
            return $this->playerRules($club, $player, $input);
        }
    })->rules($club, $player, $input);
}

test('the players table has no nickname column', function () {
    expect(Schema::hasColumn('players', 'nickname'))->toBeFalse();
});

test('staff create rejects a duplicate name in another case, and the service enforces it too', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'Sam Smith']);

    $validator = Validator::make(['name' => 'sam SMITH', 'stars' => 3], nameRules($club, null, ['stars' => 3]));
    expect($validator->fails())->toBeTrue()->and($validator->errors()->keys())->toContain('name');

    expect(fn () => app(PlayerService::class)->create($club, ['name' => ' SAM smith ', 'stars' => 3]))
        ->toThrow(ValidationException::class);

    // Another club may reuse the name.
    $other = app(PlayerService::class)->create(Club::factory()->create(), ['name' => 'Sam Smith', 'stars' => 3]);
    expect($other->name)->toBe('Sam Smith');
});

test('staff update rejects a name taken by another player but allows keeping or re-casing its own', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'Ace']);
    $mine = Player::factory()->for($club)->create(['name' => 'Bea']);

    $rules = nameRules($club, $mine);
    expect(Validator::make(['name' => 'ACE'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['name' => 'bea'], $rules)->fails())->toBeFalse();

    expect(fn () => app(PlayerService::class)->update($mine, ['name' => 'aCe']))->toThrow(ValidationException::class);
    expect(app(PlayerService::class)->update($mine, ['name' => 'BEA'])->name)->toBe('BEA');
});

test('self-register rejects an existing name in any case with the roster message', function () {
    $club = Club::factory()->create();
    Player::factory()->for($club)->create(['name' => 'Rocky']);
    $session = PlaySession::factory()->live()->for($club)->create();

    try {
        app(SelfCheckInService::class)->register($session, (string) $session->checkin_token, 'rOCKY', null, 3, '9.9.9.1');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['name'][0])->toBe('You are already on the roster. Search for your name instead.');
    }

    expect($club->players()->count())->toBe(1);
});

test('the public read model shows names exactly as entered', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->live()->for($club)->create();
    foreach (['Adrianne Basuel', 'Ace', 'Mary Jane van der Berg', 'Dee'] as $name) {
        app(CheckInService::class)->checkIn($session, Player::factory()->for($club)->create(['name' => $name]));
    }

    $json = (string) json_encode(app(PublicSessionView::class)->snapshot($session->fresh()));

    expect($json)->toContain('Adrianne Basuel')->toContain('Ace')->toContain('Mary Jane van der Berg')
        ->not->toContain('Adrianne B.');
});

test('the migration renames duplicate names per club, keeps the first, and avoids taken names', function () {
    $path = collect(glob(database_path('migrations/*_make_player_name_unique_and_drop_nickname.php')))->first();
    $migration = require $path;

    // Go back to the pre-migration shape (nickname column, no unique name), then seed duplicates.
    $migration->down();
    $a = Club::factory()->create();
    $b = Club::factory()->create();
    $ids = [];
    foreach ([[$a, 'Sam'], [$a, 'sam'], [$a, 'SAM '], [$a, 'Sam 2'], [$a, 'Solo'], [$b, 'Sam']] as $i => [$club, $name]) {
        $ids[$i] = DB::table('players')->insertGetId([
            'club_id' => $club->id, 'name' => $name, 'public_id' => 'pid'.$i, 'stars' => 3,
            'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    $name = fn (int $i) => DB::table('players')->where('id', $ids[$i])->value('name');
    expect(Schema::hasColumn('players', 'nickname'))->toBeFalse()
        ->and($name(0))->toBe('Sam')          // first by id keeps the name
        ->and($name(3))->toBe('Sam 2')        // an existing "Sam 2" is untouched
        ->and($name(1))->toBe('sam 3')        // "sam 2" is taken, so the next free suffix
        ->and($name(2))->toBe('SAM 4')
        ->and($name(4))->toBe('Solo')
        ->and($name(5))->toBe('Sam');         // other clubs are independent

    expect(Schema::hasIndex('players', 'players_club_id_name_index'))->toBeFalse();

    expect(fn () => DB::table('players')->insert([
        'club_id' => $a->id, 'name' => 'Solo', 'public_id' => 'pidx', 'stars' => 3,
        'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('the migration keeps a suffixed duplicate within 120 characters', function () {
    $migration = require collect(glob(database_path('migrations/*_make_player_name_unique_and_drop_nickname.php')))->first();
    $migration->down();
    $club = Club::factory()->create();
    $long = str_repeat('x', 120);
    foreach ([0, 1] as $i) {
        DB::table('players')->insert([
            'club_id' => $club->id, 'name' => $long, 'public_id' => 'lng'.$i, 'stars' => 3,
            'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    $names = DB::table('players')->orderBy('id')->pluck('name')->all();
    expect($names[0])->toBe($long)
        ->and($names[1])->toBe(str_repeat('x', 118).' 2')
        ->and(mb_strlen($names[1]))->toBe(120);
});

test('the migration down() restores nickname and its unique index, and up() can run again', function () {
    $migration = require collect(glob(database_path('migrations/*_make_player_name_unique_and_drop_nickname.php')))->first();

    $migration->down();

    expect(Schema::hasColumn('players', 'nickname'))->toBeTrue()
        ->and(Schema::hasIndex('players', 'players_club_id_nickname_unique'))->toBeTrue()
        ->and(Schema::hasIndex('players', 'players_club_id_name_unique'))->toBeFalse()
        ->and(Schema::hasIndex('players', 'players_club_id_name_index'))->toBeTrue();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('players', 'nickname'))->toBeFalse()
        ->and(Schema::hasIndex('players', 'players_club_id_name_unique'))->toBeTrue();
});

test('self-register succeeds when the name exists only in another club', function () {
    Player::factory()->for(Club::factory()->create())->create(['name' => 'Rocky']);
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();

    $res = app(SelfCheckInService::class)->register($session, (string) $session->checkin_token, 'Rocky', null, 3, '9.9.9.2');

    expect($res['public_name'])->toBe('Rocky')
        ->and(Player::query()->where('club_id', $session->club_id)->where('name', 'Rocky')->exists())->toBeTrue();
});

/** Inserts a conflicting row right before the next player insert, i.e. after every pre-check passed. */
function raceNextPlayerInsert(int $clubId, string $name): void
{
    $fired = false;
    Player::creating(function () use (&$fired, $clubId, $name): void {
        if ($fired) {
            return;
        }
        $fired = true;
        DB::table('players')->insert([
            'club_id' => $clubId, 'name' => $name, 'public_id' => 'race'.random_int(1000, 9999), 'stars' => 3,
            'rating_source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

test('a unique violation that slips past the pre-check becomes a name error on self-register', function () {
    $session = PlaySession::factory()->live()->for(Club::factory()->create())->create();
    raceNextPlayerInsert($session->club_id, 'Racer');

    try {
        app(SelfCheckInService::class)->register($session, (string) $session->checkin_token, 'Racer', null, 3, '9.9.9.3');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['name'][0])->toBe('You are already on the roster. Search for your name instead.');
    }
});

test('a unique violation that slips past the pre-check becomes a name error in PlayerService', function () {
    $club = Club::factory()->create();
    raceNextPlayerInsert($club->id, 'Racer');

    try {
        app(PlayerService::class)->create($club, ['name' => 'Racer', 'stars' => 3]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('name')->not->toHaveKey('dupr_id');
    }
});
