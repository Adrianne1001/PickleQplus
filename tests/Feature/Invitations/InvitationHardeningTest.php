<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use App\Services\ClubService;
use App\Services\InvitationService;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('the sole owner accepting a staff invite stays owner', function () {
    [$owner, $club] = inviteSetup();
    // The invite was sent before this address became the owner's (invite() refuses current members).
    [, $token] = sendInvite($club, $owner, 'owner@example.com', ClubRole::Staff);
    $owner->forceFill(['email' => 'owner@example.com'])->save();

    $this->actingAs($owner)->post(route('invitations.accept', $token))
        ->assertRedirect(route('clubs.show', $club))
        ->assertSessionHas('flash', "You're already a member of {$club->name}.");

    expect($owner->roleIn($club))->toBe(ClubRole::Owner)
        ->and($club->invitations()->firstOrFail()->accepted_at)->not->toBeNull();
});

test('a staff member accepting an owner invite stays staff and the invite is consumed', function () {
    [$owner, $club] = inviteSetup();
    $staff = User::factory()->create(['email' => 'staff@example.com']);
    [$invitation, $token] = sendInvite($club, $owner, 'staff@example.com', ClubRole::Owner);
    $club->users()->attach($staff->id, ['role' => ClubRole::Staff->value]);
    User::flushRoleCache();

    $result = app(InvitationService::class)->accept($invitation, $staff);

    expect($result->joined)->toBeFalse()
        ->and($staff->roleIn($result->club))->toBe(ClubRole::Staff)
        ->and($invitation->fresh()?->accepted_at)->not->toBeNull()
        ->and(app(InvitationService::class)->findPendingByToken($token))->toBeNull();
});

test('repeated invites for one email leave a single pending row', function () {
    [$owner, $club] = inviteSetup();
    Notification::fake();
    $service = app(InvitationService::class);

    $service->invite($club, $owner, 'dup@example.com', ClubRole::Staff);
    $service->invite($club, $owner, ' DUP@example.com ', ClubRole::Owner);

    expect(ClubInvitation::query()->where('club_id', $club->id)->whereNull('accepted_at')->count())->toBe(1);
});

test('the invitation email renders club and inviter names as literal text', function () {
    [$owner, $club] = inviteSetup();
    $owner->forceFill(['name' => '*Boss* [x](https://evil2.example)'])->save();
    $club->forceFill(['name' => '[Claim](https://evil.example) _go_'])->save();
    [$invitation, $token] = sendInvite($club, $owner, 'x@example.com');

    $mail = (new ClubInvitationNotification($invitation, $token, $club->name, $owner->name))->toMail(new stdClass);
    $html = (string) $mail->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->not->toContain('href="https://evil2.example')
        ->not->toContain('<em>')
        ->not->toContain('<strong>')
        ->toContain('[Claim](https://evil.example)')
        ->toContain('*Boss*');
});

test('escapeMarkdown escapes inline control characters anywhere and block markers only at the start', function () {
    $inline = ['\\', '`', '*', '_', '[', ']', '~'];

    expect(ClubInvitationNotification::escapeMarkdown('x'.implode('', $inline)))
        ->toBe('x'.implode('', array_map(fn (string $c): string => '\\'.$c, $inline)));

    foreach (['#', '>', '+', '-', '='] as $marker) {
        expect(ClubInvitationNotification::escapeMarkdown("{$marker} x"))->toBe("\\{$marker} x")
            ->and(ClubInvitationNotification::escapeMarkdown("x {$marker} y"))->toBe("x {$marker} y");
    }

    expect(ClubInvitationNotification::escapeMarkdown('12. x'))->toBe('12\\. x')
        ->and(ClubInvitationNotification::escapeMarkdown('3) x'))->toBe('3\\) x')
        ->and(ClubInvitationNotification::escapeMarkdown('Court 3. x'))->toBe('Court 3. x')
        ->and(ClubInvitationNotification::escapeMarkdown('A & B <Club>'))->toBe('A & B <Club>');
});

function renderedInvite(string $clubName, string $inviterName, ?string &$text = null): string
{
    [$owner, $club] = inviteSetup();
    $club->forceFill(['name' => $clubName])->save();
    [$invitation, $token] = sendInvite($club, $owner, 'jane.doe@example.com');

    $mail = (new ClubInvitationNotification($invitation, $token, $clubName, $inviterName))->toMail(new stdClass);
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

    return (string) $mail->render();
}

test('ampersands and angle brackets in names are encoded once in the html', function () {
    $html = renderedInvite('A & B <Club>', 'Boss');

    expect($html)->toContain('A &amp; B &lt;Club&gt;')
        ->not->toContain('&amp;amp;')
        ->not->toContain('&amp;lt;')
        ->not->toContain('<Club>');
});

test('the text part reads naturally: dots and the invitee email are not escaped', function () {
    renderedInvite('St. Louis_x', 'Boss', $text);

    expect($text)->toContain('St. Louis\\_x')
        ->toContain('jane.doe@example.com')
        ->not->toContain('\\.')
        ->not->toContain('\\@');
});

test('a leading tilde fence in the inviter name does not swallow the button', function () {
    $html = renderedInvite('Club', '~~~ x');

    expect($html)->not->toContain('<pre>')
        ->not->toContain('<code')
        ->toContain('View invitation')
        ->toContain('class="button');
});

test('an escaped markdown link in the club name still produces no link', function () {
    $html = renderedInvite('[Claim](https://evil.example)', 'Boss');

    expect($html)->not->toContain('href="https://evil.example')
        ->toContain('[Claim](https://evil.example)');
});

test('User::switcherClubs lists the clubs by name with the pivot role', function () {
    $user = User::factory()->create();
    $b = Club::factory()->withStaff($user)->create(['name' => 'Bravo']);
    $a = Club::factory()->withOwner($user)->create(['name' => 'Alpha']);
    Club::factory()->withOwner()->create(['name' => 'Elsewhere']);

    $clubs = $user->switcherClubs();

    expect($clubs->pluck('name')->all())->toBe(['Alpha', 'Bravo'])
        ->and($clubs->first()?->pivot->role)->toBe(ClubRole::Owner)
        ->and($clubs->last()?->pivot->role)->toBe(ClubRole::Staff);
});

test('an existing member opening and accepting an invite sees the already-a-member flash and keeps their role', function () {
    [$owner, $club] = inviteSetup();
    $staff = User::factory()->create(['email' => 'staff2@example.com']);
    [, $token] = sendInvite($club, $owner, 'staff2@example.com', ClubRole::Owner);
    $club->users()->attach($staff->id, ['role' => ClubRole::Staff->value]);
    User::flushRoleCache();

    $this->actingAs($staff)->get(route('invitations.show', $token))->assertOk();
    $this->actingAs($staff)->followingRedirects()
        ->post(route('invitations.accept', $token))
        ->assertOk()
        ->assertSeeHtml('data-test="flash-status"')
        ->assertSee(e("You're already a member of {$club->name}."), false);

    User::flushRoleCache();
    expect($staff->roleIn($club))->toBe(ClubRole::Staff)
        ->and($club->invitations()->firstOrFail()->accepted_at)->not->toBeNull();
});

test('a normal accept shows the joined flash in the flash-status element', function () {
    [$owner, $club] = inviteSetup();
    [, $token] = sendInvite($club, $owner, 'newcomer@example.com', ClubRole::Staff);
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($user)->followingRedirects()
        ->post(route('invitations.accept', $token))
        ->assertOk()
        ->assertSeeHtml('data-test="flash-status"')
        ->assertSee('You joined '.e($club->name).'.', false)
        ->assertDontSee('already a member');
});

test('a resend replaces the link: the old token and the old model both fail to accept', function () {
    [$owner, $club] = inviteSetup();
    [$invitation, $oldToken] = sendInvite($club, $owner, 'rot@example.com');
    $user = User::factory()->create(['email' => 'rot@example.com']);
    $service = app(InvitationService::class);

    $old = ClubInvitation::query()->findOrFail($invitation->id);
    Notification::fake();
    $service->resend($invitation);

    expect(fn () => $service->accept($old, $user))->toThrow(ValidationException::class, 'replaced')
        ->and($service->findByToken($oldToken))->toBeNull()
        ->and($club->users()->where('users.id', $user->id)->exists())->toBeFalse();
});

test('accept reports whether the user joined', function () {
    [$owner, $club] = inviteSetup();
    [$invitation] = sendInvite($club, $owner, 'new@example.com');
    $user = User::factory()->create(['email' => 'new@example.com']);

    $result = app(InvitationService::class)->accept($invitation, $user);

    expect($result->joined)->toBeTrue()->and($result->club->is($club))->toBeTrue();
});

test('addMember returns false for an existing member and true for a new one', function () {
    [$owner, $club] = inviteSetup();
    $other = User::factory()->create();
    $clubs = app(ClubService::class);

    expect($clubs->addMember($club, $other, ClubRole::Staff))->toBeTrue()
        ->and($clubs->addMember($club, $other, ClubRole::Owner))->toBeFalse()
        ->and($other->roleIn($club))->toBe(ClubRole::Staff);
});

test('the invitation page tells an existing member that their role will not change', function () {
    [$owner, $club] = inviteSetup();
    $staff = User::factory()->create(['email' => 'mem@example.com']);
    [, $token] = sendInvite($club, $owner, 'mem@example.com', ClubRole::Owner);
    $club->users()->attach($staff->id, ['role' => ClubRole::Staff->value]);
    User::flushRoleCache();

    $this->actingAs($staff)->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee(e("already a member of {$club->name} (staff)"), false)
        ->assertSee('change your role', false)
        ->assertDontSee('invited to join as owner');
});

test('the raw verification-link-sent status is not shown as a flash callout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['status' => 'verification-link-sent'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSeeHtml('data-test="flash-status"');
});
