<?php

use App\Concerns\ClubValidationRules;
use App\Livewire\Inputs\ClubInput;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Rules\ClubSlug;
use App\Rules\DuprPlayerId;
use App\Services\ClubService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

test('generated slugs never exceed 50 characters, suffix included', function () {
    config(['pickleq.max_owned_clubs' => 10]);
    $service = app(ClubService::class);
    $name = str_repeat('abcdefghi ', 10);
    $user = User::factory()->create();

    $slugs = [];
    foreach (range(1, 3) as $i) {
        $slugs[] = $service->create($user, ['name' => $name])->slug;
    }

    expect(array_unique($slugs))->toHaveCount(3)
        ->and(max(array_map('strlen', $slugs)))->toBeLessThanOrEqual(50)
        ->and($slugs[1])->toEndWith('-2')
        ->and($slugs[2])->toEndWith('-3');

    foreach ($slugs as $slug) {
        expect(Validator::make(['slug' => $slug], ['slug' => ['max:50', new ClubSlug]])->passes())->toBeTrue();
    }
});

test('a slug collision at insert time retries with the next suffix', function () {
    Club::factory()->create(['name' => 'Taken', 'slug' => 'racy-club']);
    $user = User::factory()->create();

    // Simulates the race: the first slug looked free but was taken before the insert.
    $service = new class extends ClubService
    {
        public int $calls = 0;

        public function uniqueSlug(string $name, ?Club $ignore = null): string
        {
            return ++$this->calls === 1 ? 'racy-club' : parent::uniqueSlug($name, $ignore);
        }
    };

    $club = $service->create($user, ['name' => 'Racy Club']);

    expect($club->slug)->toBe('racy-club-2')
        ->and($service->calls)->toBe(2)
        ->and(Club::count())->toBe(2);
});

test('create and update normalize their input', function () {
    $user = User::factory()->create();
    $service = app(ClubService::class);

    $club = $service->create($user, ['name' => '  Padded Club  ', 'dupr_club_id' => '   ']);
    expect($club->name)->toBe('Padded Club')->and($club->dupr_club_id)->toBeNull();

    $service->update($club, ['name' => ' Renamed ', 'slug' => ' renamed-club ', 'dupr_club_id' => ' 1234567890 ']);
    expect($club->fresh()->name)->toBe('Renamed')
        ->and($club->fresh()->slug)->toBe('renamed-club')
        ->and($club->fresh()->dupr_club_id)->toBe('1234567890');

    $service->update($club, ['dupr_club_id' => '']);
    expect($club->fresh()->dupr_club_id)->toBeNull();
});

test('updating to a taken slug is a validation error', function () {
    Club::factory()->create(['slug' => 'taken']);
    $club = Club::factory()->create();

    expect(fn () => app(ClubService::class)->update($club, ['slug' => 'taken']))
        ->toThrow(ValidationException::class);
});

test('trailing newlines are rejected by the rules and normalized by the club forms', function () {
    $club = Club::factory()->create();

    expect(Validator::make(['slug' => "my-club\n"], ['slug' => [new ClubSlug]])->fails())->toBeTrue();

    $rules = (new class
    {
        use ClubValidationRules;

        /** @return array<int, string> */
        public function rules(): array
        {
            return $this->duprClubIdRules();
        }
    })->rules();
    expect(Validator::make(['id' => "1234567890\n"], ['id' => $rules])->fails())->toBeTrue();

    $input = new ClubInput;
    $data = $input->update($club, ['name' => "Name\n", 'slug' => "my-club\n", 'dupr_club_id' => "1234567890\n", 'default_courts' => '4']);
    expect($data['slug'])->toBe('my-club')->and($data['dupr_club_id'])->toBe('1234567890');

    $created = $input->create(['name' => 'X', 'dupr_club_id' => '', 'default_courts' => '']);
    expect($created['dupr_club_id'])->toBeNull()->and($created['default_courts'])->toBeNull();
});

test('a DUPR player id with a trailing newline is not accepted raw', function () {
    expect(preg_match(DuprPlayerId::PATTERN, "ABC123\n"))->toBe(0)
        ->and(DuprPlayerId::normalize("abc123\n"))->toBe('ABC123');
});

test('star band recompute crosses the chunk boundary', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    Player::factory()->count(450)->for($club)->rated(3.2)->create();

    app(ClubService::class)->updateStarBands($club, [3.0, 3.1, 3.2, 3.3, 3.4]);

    expect($club->players()->where('stars', 4)->count())->toBe(450);
});
