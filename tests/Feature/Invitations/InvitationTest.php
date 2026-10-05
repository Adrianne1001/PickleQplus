<?php

use App\Enums\ClubRole;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Validation\ValidationException;

test('inviting the same email again refreshes the pending invite', function () {
    [$owner, $club] = inviteSetup();

    [$first, $oldToken] = sendInvite($club, $owner, 'a@example.com', ClubRole::Staff);
    [$second, $newToken] = sendInvite($club, $owner, 'A@example.com', ClubRole::Owner);

    expect($club->invitations()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->role)->toBe(ClubRole::Owner)
        ->and(app(InvitationService::class)->findPendingByToken($oldToken))->toBeNull()
        ->and(app(InvitationService::class)->findPendingByToken($newToken))->not->toBeNull();
});

test('pending invitations relation lists unaccepted invites', function () {
    [$owner, $club] = inviteSetup();
    sendInvite($club, $owner, 'a@example.com');
    [$b] = sendInvite($club, $owner, 'b@example.com');
    $b->forceFill(['accepted_at' => now()])->save();

    expect($club->pendingInvitations()->pluck('email')->all())->toBe(['a@example.com']);
});

test('accepting adds the membership and sets the current club', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'joiner@example.com', ClubRole::Staff);
    $user = User::factory()->create(['email' => 'Joiner@example.com']);

    $this->actingAs($user)->get(route('invitations.show', $token))->assertOk()->assertSee($club->name);
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('clubs.show', $club));

    expect($user->roleIn($club))->toBe(ClubRole::Staff)
        ->and($user->fresh()?->current_club_id)->toBe($club->id)
        ->and($club->invitations()->firstOrFail()->accepted_at)->not->toBeNull();
});

test('a used token cannot be reused', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'joiner@example.com');
    $user = User::factory()->create(['email' => 'joiner@example.com']);

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect();
    $this->actingAs($user)->get(route('invitations.show', $token))->assertStatus(410)->assertSee('already used');
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertStatus(410);

    expect($club->users()->where('users.id', $user->id)->count())->toBe(1);
});

test('the service refuses a second accept on a stale model', function () {
    [$owner, $club] = inviteSetup();
    [$invitation] = sendInvite($club, $owner, 'joiner@example.com');
    $user = User::factory()->create(['email' => 'joiner@example.com']);

    $service = app(InvitationService::class);
    $stale = ClubInvitation::query()->findOrFail($invitation->id);
    $service->accept($invitation, $user);

    expect(fn () => $service->accept($stale, $user))->toThrow(ValidationException::class);
});

test('a different account sees a masked error and is not added', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'jane@example.com');
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($other)->get(route('invitations.show', $token))
        ->assertForbidden()
        ->assertSee('j***@example.com')
        ->assertDontSee('jane@example.com');

    $this->actingAs($other)->post(route('invitations.accept', $token))
        ->assertSessionHasErrors('invitation');

    expect($other->belongsToClub($club))->toBeFalse();
});

test('unverified users cannot accept', function () {
    [$owner, $club] = inviteSetup();
    [$invitation, $token] = sendInvite($club, $owner, 'jane@example.com');
    $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);

    $this->actingAs($user)->get(route('invitations.show', $token))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('verification.notice'));
    expect(fn () => app(InvitationService::class)->accept($invitation, $user))->toThrow(ValidationException::class);

    expect($user->belongsToClub($club))->toBeFalse();
});

test('expired invitations show a friendly page and cannot be accepted', function () {
    [$owner, $club] = inviteSetup();
    [$invitation, $token] = sendInvite($club, $owner, 'jane@example.com');
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->actingAs($user)->get(route('invitations.show', $token))->assertStatus(410)->assertSee('expired');
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertStatus(410);
    expect(fn () => app(InvitationService::class)->accept($invitation, $user))->toThrow(ValidationException::class)
        ->and(app(InvitationService::class)->findPendingByToken($token))->toBeNull();
});

test('unknown tokens show a friendly page', function () {
    $this->get(route('invitations.show', 'nope'))->assertStatus(410)->assertSee('not found');
});

test('a guest opening the link is sent to log in or register and returns after login', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'jane@example.com');

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false)
        ->assertSee('j***@example.com')
        ->assertSessionHas('url.intended', route('invitations.show', $token));

    User::factory()->create(['email' => 'jane@example.com']);
    $this->post(route('login.store'), ['email' => 'jane@example.com', 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $token));
});

test('a guest who registers lands back on the invitation', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'jane@example.com');

    $this->get(route('invitations.show', $token));
    $this->post(route('register.store'), [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', $token));
});

test('maskEmail hides all but the first character of the local part', function () {
    expect(InvitationService::maskEmail('jane@example.com'))->toBe('j***@example.com');
});
