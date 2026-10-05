<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Services\ClubService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('the members page lists members with roles', function () {
    $owner = User::factory()->create(['name' => 'Olive Owner']);
    $staff = User::factory()->create(['name' => 'Sam Staff']);
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    $this->actingAs($owner)->get(route('clubs.members', $club))
        ->assertOk()
        ->assertSee('Olive Owner')
        ->assertSee('Sam Staff')
        ->assertSee('data-test="invite-card"', false);
});

test('staff see members as read-only and no invite card', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->assertDontSeeHtml('data-test="role-select"')
        ->assertDontSeeHtml('data-test="remove-member-button"')
        ->assertDontSeeHtml('data-test="invite-card"')
        ->assertSeeHtml('data-test="leave-club-button"');
});

test('staff cannot change roles', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $staff->id, 'owner')
        ->assertForbidden();

    expect($staff->roleIn($club))->toBe(ClubRole::Staff);
});

test('the last owner cannot be demoted or removed', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $service = app(ClubService::class);

    expect(fn () => $service->changeRole($club, $owner, ClubRole::Staff))->toThrow(ValidationException::class);
    expect(fn () => $service->removeMember($club, $owner))->toThrow(ValidationException::class);

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $owner->id, 'staff')
        ->assertHasErrors('member')
        ->assertSee('A club must keep at least one owner.')
        ->call('removeMember', $owner->id)
        ->assertHasErrors('member');

    expect($owner->roleIn($club))->toBe(ClubRole::Owner);
});

test('an owner can be demoted or leave when another owner exists', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $club = Club::factory()->withOwner($a)->create();
    $service = app(ClubService::class);
    $service->addMember($club, $b, ClubRole::Owner);

    $service->changeRole($club, $a, ClubRole::Staff);
    expect($a->roleIn($club))->toBe(ClubRole::Staff);

    // $b is now the last owner and cannot leave.
    Livewire::actingAs($b)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $b->id)
        ->assertHasErrors('member');

    $service->changeRole($club, $a, ClubRole::Owner);
    Livewire::actingAs($b)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $b->id)
        ->assertRedirect(route('dashboard'));
    expect($b->belongsToClub($club))->toBeFalse();
});

test('staff can leave a club but not remove others', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $owner->id)
        ->assertForbidden();
    expect($owner->belongsToClub($club))->toBeTrue();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $staff->id)
        ->assertRedirect(route('dashboard'));
    expect($staff->belongsToClub($club))->toBeFalse();
});

test('owners can change a member role', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $staff->id, 'owner')
        ->assertHasNoErrors();

    expect($staff->roleIn($club))->toBe(ClubRole::Owner);
});

test('an invalid role is rejected', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $staff->id, 'admin')
        ->assertHasErrors('member');

    expect($staff->roleIn($club))->toBe(ClubRole::Staff);
});

test('owners can remove a staff member', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $staff->id)
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($staff->belongsToClub($club))->toBeFalse();
});

test('members of another club cannot be reached through this club', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $otherOwner = User::factory()->create();
    $other = Club::factory()->withOwner($otherOwner)->create();

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('changeRole', $otherOwner->id, 'staff')
        ->assertNotFound();
    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('removeMember', $otherOwner->id)
        ->assertNotFound();

    expect($otherOwner->roleIn($other))->toBe(ClubRole::Owner);
});

test('a refused role change re-keys the dropdown so it reverts', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    $component = Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club]);
    $before = $component->get('roleResync');

    $component->call('changeRole', $owner->id, 'staff')
        ->assertHasErrors('member')
        ->assertSet('roleResync', $before + 1)
        ->assertSeeHtml('wire:key="role-'.$owner->id.'-owner-'.($before + 1).'"');
});
