<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('end to end: invite, mail, register, verify, land on the invite, accept', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    Notification::fake();
    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->set('inviteEmail', 'Newbie@Example.com')
        ->set('inviteRole', 'staff')
        ->call('invite')
        ->assertHasNoErrors();

    $url = null;
    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n, $channels, $notifiable) use (&$url) {
        $url = $n->url();

        return strtolower($notifiable->routes['mail']) === 'newbie@example.com';
    });
    auth()->logout();
    $this->flushSession();

    // The invitee opens the mailed link as a guest, then registers with that email.
    $this->get($url)->assertOk()->assertSee(route('register'), false);
    $this->post(route('register.store'), [
        'name' => 'Newbie',
        'email' => 'newbie@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect($url);

    $user = User::where('email', 'newbie@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();

    // Unverified: the invite sends them to verify first, and they are not a member.
    $this->get($url)->assertRedirect(route('verification.notice'));
    expect($user->belongsToClub($club))->toBeFalse();

    // Verifying returns them to the invite.
    $verify = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $this->get($verify)->assertRedirect($url);

    $this->get($url)->assertOk()->assertSee($club->name);
    $this->post(route('invitations.accept', ['token' => basename($url)]))->assertRedirect(route('clubs.show', $club));

    expect($user->fresh()?->roleIn($club))->toBe(ClubRole::Staff)
        ->and($user->fresh()?->current_club_id)->toBe($club->id)
        ->and($club->invitations()->count())->toBe(1)
        ->and($club->pendingInvitations()->count())->toBe(0);
});

test('a revoked invitation link is dead', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    Notification::fake();
    $invitation = app(InvitationService::class)->invite($club, $owner, 'jane@example.com', ClubRole::Staff);
    $token = null;
    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n) use (&$token) {
        $token = $n->plainToken;

        return true;
    });

    Livewire::actingAs($owner)->test('pages::clubs.members', ['club' => $club])
        ->call('revokeInvitation', $invitation->id);

    $jane = User::factory()->create(['email' => 'jane@example.com']);
    $this->actingAs($jane)->get(route('invitations.show', $token))->assertStatus(410);
    $this->actingAs($jane)->post(route('invitations.accept', $token))->assertStatus(410);

    expect($jane->belongsToClub($club))->toBeFalse();
});
