<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

test('the service throttles invites per club, whoever calls it', function () {
    Notification::fake();
    config(['pickleq.invitations_per_hour' => 3]);
    [$owner, $club] = inviteSetup();
    $other = Club::factory()->withOwner()->create();
    $service = app(InvitationService::class);

    foreach (range(1, 3) as $i) {
        $service->invite($club, $owner, "p{$i}@example.com", ClubRole::Staff);
    }

    try {
        $service->invite($club, $owner, 'p4@example.com', ClubRole::Staff);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['email'][0])->toContain('Try again in')->toContain('minute');
    }

    // Resend shares the same budget.
    expect(fn () => $service->resend($club->invitations()->firstOrFail()))->toThrow(ValidationException::class);

    // Another club is unaffected.
    $service->invite($other, $owner, 'x@example.com', ClubRole::Staff);

    // Once the window resets, invites go through again.
    RateLimiter::clear('invitations:service:club:'.$club->id);
    $service->invite($club, $owner, 'p4@example.com', ClubRole::Staff);

    expect($club->invitations()->count())->toBe(4);
});
