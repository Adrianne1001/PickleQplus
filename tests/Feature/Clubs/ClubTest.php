<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\Player;
use App\Models\User;
use App\Services\ClubService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('creating a club makes the creator its owner', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::clubs.create')
        ->set('name', 'Sunset Pickle')
        ->call('create')
        ->assertHasNoErrors();

    $club = Club::where('name', 'Sunset Pickle')->firstOrFail();
    $component->assertRedirect(route('clubs.show', $club));
    expect($club->slug)->toBe('sunset-pickle')
        ->and($user->roleIn($club))->toBe(ClubRole::Owner)
        ->and($club->star_bands)->toEqual(config('pickleq.star_bands'))
        ->and($club->default_courts)->toBe(4);
});

test('the create form saves the DUPR club id and courts', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::clubs.create')
        ->set('name', 'Full Club')
        ->set('dupr_club_id', '1234567890')
        ->set('default_courts', '8')
        ->call('create')
        ->assertHasNoErrors();

    $club = Club::where('name', 'Full Club')->firstOrFail();
    expect($club->dupr_club_id)->toBe('1234567890')->and($club->default_courts)->toBe(8);
});

test('the create form is validated', function (array $props, string $errorKey) {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::clubs.create')->set('name', 'Ok');
    foreach ($props as $key => $value) {
        $component->set($key, $value);
    }
    $component->call('create')->assertHasErrors($errorKey);

    expect(Club::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'short dupr club id' => [['dupr_club_id' => '12345'], 'dupr_club_id'],
    'letters in dupr club id' => [['dupr_club_id' => '12345abcde'], 'dupr_club_id'],
    'zero courts' => [['default_courts' => '0'], 'default_courts'],
    'too many courts' => [['default_courts' => '31'], 'default_courts'],
]);

test('the first-club empty state is shown to users without clubs', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('clubs.create'))
        ->assertOk()
        ->assertSee('Create your first club')
        ->assertDontSee('data-test="club-nav"', false);
});

test('the create page for users with clubs is a plain create form', function () {
    $user = User::factory()->create();
    Club::factory()->withOwner($user)->create();

    $this->actingAs($user)->get(route('clubs.create'))
        ->assertOk()
        ->assertDontSee('Create your first club');
});

test('slugs are made unique', function () {
    $user = User::factory()->create();
    $service = app(ClubService::class);

    $a = $service->create($user, ['name' => 'Same Name']);
    $b = $service->create($user, ['name' => 'Same Name']);
    $c = $service->create($user, ['name' => 'Create']);

    expect($a->slug)->toBe('same-name')
        ->and($b->slug)->toBe('same-name-2')
        ->and($c->slug)->toBe('create-2');
});

test('owned club cap is enforced', function () {
    config(['pickleq.max_owned_clubs' => 2]);
    $user = User::factory()->create();
    $service = app(ClubService::class);

    $service->create($user, ['name' => 'One']);
    $service->create($user, ['name' => 'Two']);

    expect(fn () => $service->create($user, ['name' => 'Three']))->toThrow(ValidationException::class);

    Livewire::actingAs($user)->test('pages::clubs.create')
        ->set('name', 'Three')
        ->call('create')
        ->assertHasErrors('name');
    expect(Club::count())->toBe(2);
});

test('being staff elsewhere does not count toward the cap', function () {
    config(['pickleq.max_owned_clubs' => 1]);
    $user = User::factory()->create();
    Club::factory()->withStaff($user)->create();

    $club = app(ClubService::class)->create($user, ['name' => 'Mine']);

    expect($club->exists)->toBeTrue();
});

test('unverified users are redirected away from club routes', function () {
    $user = User::factory()->unverified()->create();
    $club = Club::factory()->withOwner($user)->create();

    $this->actingAs($user);
    $this->get(route('clubs.create'))->assertRedirect(route('verification.notice'));
    $this->get(route('clubs.show', $club))->assertRedirect(route('verification.notice'));
    $this->get(route('clubs.settings', $club))->assertRedirect(route('verification.notice'));
    $this->get(route('clubs.members', $club))->assertRedirect(route('verification.notice'));
    $this->get(route('clubs.players.index', $club))->assertRedirect(route('verification.notice'));
});

test('unverified users cannot create clubs through the component', function () {
    Livewire::actingAs(User::factory()->unverified()->create())
        ->test('pages::clubs.create')
        ->assertForbidden();

    expect(Club::count())->toBe(0);
});

test('guests are redirected to login from club routes', function () {
    $club = Club::factory()->withOwner()->create();

    $this->get(route('clubs.create'))->assertRedirect(route('login'));
    $this->get(route('clubs.show', $club))->assertRedirect(route('login'));
    $this->get(route('clubs.settings', $club))->assertRedirect(route('login'));
    $this->get(route('clubs.members', $club))->assertRedirect(route('login'));
    $this->get(route('clubs.players.index', $club))->assertRedirect(route('login'));
});

test('non-members get 404 on club routes', function () {
    $club = Club::factory()->withOwner()->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider);
    $this->get(route('clubs.show', $club))->assertNotFound();
    $this->get(route('clubs.settings', $club))->assertNotFound();
    $this->get(route('clubs.members', $club))->assertNotFound();
    $this->get(route('clubs.players.index', $club))->assertNotFound();
    $this->get('/clubs/does-not-exist')->assertNotFound();
});

test('non-members cannot mount club components directly', function (string $component) {
    $club = Club::factory()->withOwner()->create();

    Livewire::actingAs(User::factory()->create())
        ->test($component, ['club' => $club])
        ->assertNotFound();
})->with(['pages::clubs.show', 'pages::clubs.settings', 'pages::clubs.members', 'pages::clubs.players']);

test('members can view a club and the current club is remembered', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    $this->actingAs($user)->get(route('clubs.show', $club))->assertOk()->assertSee($club->name);

    expect($user->fresh()->current_club_id)->toBe($club->id);
});

test('the overview shows counts and quick links', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff()->create();
    Player::factory()->for($club)->count(3)->create();
    Player::factory()->for($club)->inactive()->create();

    Livewire::actingAs($owner)->test('pages::clubs.show', ['club' => $club])
        ->assertSeeHtml('data-test="sessions-placeholder"')
        ->assertSee(route('clubs.players.index', $club))
        ->assertSee(route('clubs.members', $club))
        ->assertSee(route('clubs.settings', $club))
        ->assertSeeInOrder(['Active players', '3'])
        ->assertSeeInOrder(['Members', '2']);
});

test('staff do not see the settings link on the overview', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.show', ['club' => $club])
        ->assertDontSee(route('clubs.settings', $club));
});

test('the club property is locked against tampering', function () {
    $user = User::factory()->create();
    $mine = Club::factory()->withOwner($user)->create();
    $other = Club::factory()->withOwner()->create();

    $component = Livewire::actingAs($user)->test('pages::clubs.settings', ['club' => $mine]);

    expect(fn () => $component->set('club', $other->id))->toThrow(Exception::class, 'Cannot update locked property');
});

test('owners can update club settings', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    Livewire::actingAs($user)->test('pages::clubs.settings', ['club' => $club])
        ->set('name', 'Renamed')
        ->set('slug', 'renamed-club')
        ->set('dupr_club_id', '1234567890')
        ->set('default_courts', '6')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertRedirect(route('clubs.settings', ['club' => 'renamed-club']));

    $club->refresh();
    expect($club->name)->toBe('Renamed')
        ->and($club->slug)->toBe('renamed-club')
        ->and($club->dupr_club_id)->toBe('1234567890')
        ->and($club->default_courts)->toBe(6);
});

test('keeping the slug does not redirect and clearing the DUPR club id stores null', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create(['dupr_club_id' => '1234567890']);

    Livewire::actingAs($user)->test('pages::clubs.settings', ['club' => $club])
        ->set('name', 'Same Slug')
        ->set('dupr_club_id', '')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($club->fresh()->dupr_club_id)->toBeNull()->and($club->fresh()->name)->toBe('Same Slug');
});

test('the settings page shows the resulting URL for the slug', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    Livewire::actingAs($user)->test('pages::clubs.settings', ['club' => $club])
        ->set('slug', 'my-club')
        ->assertSee(route('clubs.show', ['club' => 'my-club']));
});

test('club settings are validated', function (array $overrides, string $errorKey) {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    Club::factory()->create(['slug' => 'taken']);

    $component = Livewire::actingAs($user)->test('pages::clubs.settings', ['club' => $club])
        ->set('name', 'Ok')
        ->set('slug', 'ok-slug')
        ->set('default_courts', '4');
    foreach ($overrides as $key => $value) {
        $component->set($key, $value);
    }

    $component->call('saveDetails')->assertHasErrors($errorKey);
})->with([
    'bad slug' => [['slug' => 'Bad Slug'], 'slug'],
    'reserved slug' => [['slug' => 'create'], 'slug'],
    'taken slug' => [['slug' => 'taken'], 'slug'],
    'short dupr club id' => [['dupr_club_id' => '12345'], 'dupr_club_id'],
    'letters in dupr club id' => [['dupr_club_id' => '12345abcde'], 'dupr_club_id'],
    'zero courts' => [['default_courts' => '0'], 'default_courts'],
    'too many courts' => [['default_courts' => '31'], 'default_courts'],
]);

test('staff cannot open club settings', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    $this->actingAs($staff);
    $this->get(route('clubs.show', $club))->assertOk();
    $this->get(route('clubs.members', $club))->assertOk();
    $this->get(route('clubs.settings', $club))->assertForbidden();

    Livewire::test('pages::clubs.settings', ['club' => $club])->assertForbidden();

    expect($club->fresh()->name)->toBe($club->name);
});

test('owners can delete a club after typing its name and the member current club resets', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $this->actingAs($owner)->get(route('clubs.show', $club));

    $component = Livewire::test('pages::clubs.settings', ['club' => $club])
        ->set('confirmName', 'wrong name')
        ->call('deleteClub')
        ->assertHasErrors('confirmName');
    expect(Club::count())->toBe(1);

    $component->set('confirmName', $club->name)
        ->call('deleteClub')
        ->assertRedirect(route('dashboard'));

    expect(Club::count())->toBe(0)
        ->and($owner->fresh()->current_club_id)->toBeNull();
});

test('the sidebar shows the club switcher and nav for the current club', function () {
    $user = User::factory()->create();
    $owned = Club::factory()->withOwner($user)->create(['name' => 'Owned Club']);
    $staffed = Club::factory()->withStaff($user)->create(['name' => 'Staffed Club']);
    Club::factory()->withOwner()->create(['name' => 'Foreign Club']);

    $this->actingAs($user)->get(route('clubs.players.index', $owned))
        ->assertOk()
        ->assertSee('Owned Club')
        ->assertSee('Staffed Club')
        ->assertDontSee('Foreign Club')
        ->assertSee(route('clubs.settings', $owned))
        ->assertSee(route('clubs.members', $owned))
        ->assertSee('Create club');

    $this->get(route('clubs.show', $staffed))
        ->assertOk()
        ->assertDontSee(route('clubs.settings', $staffed));
});

test('the sidebar hides the club nav when the user has no club', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('data-test="club-nav"', false)
        ->assertSee('Create club');
});

test('the sidebar keeps the last club on non-club pages', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    $this->actingAs($user)->get(route('clubs.show', $club));

    $this->get(route('profile.edit'))->assertOk()->assertSee('data-test="club-nav"', false);
});

test('a flash message is shown in the app layout', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    $this->actingAs($owner)
        ->withSession(['flash' => 'You joined Test Club.'])
        ->get(route('clubs.show', $club))
        ->assertOk()
        ->assertSee('You joined Test Club.')
        ->assertSeeHtml('data-test="flash-status"');

});
