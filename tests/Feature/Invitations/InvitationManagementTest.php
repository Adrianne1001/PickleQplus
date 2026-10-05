<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

function mgmtSetup(): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    return [$owner, $club];
}

function mgmtInvite(Club $club, User $owner, string $email): ClubInvitation
{
    Notification::fake();

    return app(InvitationService::class)->invite($club, $owner, $email, ClubRole::Staff);
}

test('owner invites by email from the members page', function () {
    [$owner, $club] = mgmtSetup();
    Notification::fake();

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->set('inviteEmail', 'New.Person@Example.com')
        ->set('inviteRole', 'staff')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('inviteEmail', '')
        ->assertSee('new.person@example.com')
        ->assertSeeHtml('data-test="invitation-list"');

    $invitation = $club->invitations()->firstOrFail();
    expect($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(ClubRole::Staff)
        ->and($invitation->invited_by)->toBe($owner->id);

    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n) use ($invitation) {
        return $invitation->token_hash === hash('sha256', $n->plainToken);
    });
});

test('invite validates input and shows service errors inline', function () {
    [$owner, $club] = mgmtSetup();
    $member = User::factory()->create(['email' => 'Staff@Example.com']);
    $club->users()->attach($member->id, ['role' => 'staff']);

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->set('inviteEmail', 'not-an-email')
        ->call('invite')
        ->assertHasErrors('inviteEmail')
        ->set('inviteEmail', 'staff@example.com')
        ->call('invite')
        ->assertHasErrors('inviteEmail')
        ->assertSee('already a member')
        ->set('inviteEmail', 'a@example.com')
        ->set('inviteRole', 'wizard')
        ->call('invite')
        ->assertHasErrors('inviteRole');

    expect($club->invitations()->count())->toBe(0);
});

test('pending invitations show who invited and an expired badge', function () {
    [$owner, $club] = mgmtSetup();
    $invitation = mgmtInvite($club, $owner, 'late@example.com');
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();
    mgmtInvite($club, $owner, 'fresh@example.com');

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->assertSee('late@example.com')
        ->assertSee('fresh@example.com')
        ->assertSee($owner->name)
        ->assertSeeHtml('data-test="invitation-expired"');
});

test('owner can resend an invitation: new token, fresh expiry', function () {
    [$owner, $club] = mgmtSetup();
    $invitation = mgmtInvite($club, $owner, 'jane@example.com');
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    Notification::fake();
    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('resendInvitation', $invitation->id)
        ->assertHasNoErrors();

    $newToken = null;
    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n) use (&$newToken) {
        $newToken = $n->plainToken;

        return true;
    });

    expect(app(InvitationService::class)->findPendingByToken((string) $newToken))->not->toBeNull()
        ->and($invitation->fresh()?->expires_at->isFuture())->toBeTrue();
});

test('owner can revoke an invitation', function () {
    [$owner, $club] = mgmtSetup();
    $invitation = mgmtInvite($club, $owner, 'jane@example.com');

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('revokeInvitation', $invitation->id)
        ->assertDontSee('jane@example.com');

    expect($club->invitations()->count())->toBe(0);
});

test('staff see no invite card and get 403 on invitation actions', function () {
    [$owner, $club] = mgmtSetup();
    $staff = User::factory()->create();
    $club->users()->attach($staff->id, ['role' => 'staff']);
    $invitation = mgmtInvite($club, $owner, 'x@example.com');

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->assertDontSeeHtml('data-test="invite-card"')
        ->set('inviteEmail', 'y@example.com')
        ->call('invite')->assertForbidden();

    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('resendInvitation', $invitation->id)->assertForbidden();
    Livewire::actingAs($staff)->test('pages::clubs.members', ['club' => $club])
        ->call('revokeInvitation', $invitation->id)->assertForbidden();

    expect($club->invitations()->count())->toBe(1);
});

test('non-members get 404 on the members page', function () {
    [, $club] = mgmtSetup();

    Livewire::actingAs(User::factory()->create())->test('pages::clubs.members', ['club' => $club])->assertNotFound();
});

test('an invitation of another club is a 404 for resend and revoke', function () {
    [$owner, $club] = mgmtSetup();
    [$ownerB, $clubB] = mgmtSetup();
    $invitationB = mgmtInvite($clubB, $ownerB, 'x@example.com');

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('resendInvitation', $invitationB->id)->assertNotFound();
    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('revokeInvitation', $invitationB->id)->assertNotFound();

    expect(ClubInvitation::query()->count())->toBe(1);
});

test('the removed HTTP management routes no longer exist', function () {
    expect(Route::has('clubs.invitations.store'))->toBeFalse()
        ->and(Route::has('clubs.invitations.resend'))->toBeFalse()
        ->and(Route::has('clubs.invitations.destroy'))->toBeFalse();
});

test('each landing page state renders', function () {
    [$owner, $club] = mgmtSetup();
    $invitation = mgmtInvite($club, $owner, 'jane@example.com');
    $token = 'tok'.str_repeat('a', 37);
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();

    // guest
    $this->get(route('invitations.show', $token))
        ->assertOk()->assertSee($club->name)->assertSee('j***@example.com')
        ->assertSeeHtml('data-test="invitation-login"')->assertSeeHtml('data-test="invitation-register"');

    // wrong account
    $this->actingAs(User::factory()->create(['email' => 'other@example.com']))
        ->get(route('invitations.show', $token))
        ->assertForbidden()->assertSeeHtml('data-test="invitation-logout"')->assertSee('j***@example.com');

    // right account
    $this->actingAs(User::factory()->create(['email' => 'jane@example.com']))
        ->get(route('invitations.show', $token))
        ->assertOk()->assertSee($club->name)->assertSeeHtml('data-test="invitation-accept"');

    // expired, used, invalid
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->get(route('invitations.show', $token))->assertStatus(410)->assertSeeHtml('data-reason="expired"');
    $invitation->forceFill(['expires_at' => now()->addDay(), 'accepted_at' => now()])->save();
    $this->get(route('invitations.show', $token))->assertStatus(410)->assertSeeHtml('data-reason="used"');
    $this->get(route('invitations.show', 'nope'))->assertStatus(410)->assertSeeHtml('data-reason="invalid"');
});
