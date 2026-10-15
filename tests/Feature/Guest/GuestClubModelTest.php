<?php

use App\Models\Club;
use Illuminate\Database\UniqueConstraintViolationException;

test('the guest factory state makes a hidden, expiring club with only a token hash', function () {
    $club = Club::factory()->guest('abc')->create();

    expect($club->isGuest())->toBeTrue()
        ->and($club->guest_token_hash)->toBe(hash('sha256', 'abc'))
        ->and($club->guest_expires_at->isFuture())->toBeTrue()
        ->and($club->guest_claimed_at)->toBeNull()
        ->and($club->users()->count())->toBe(0)
        ->and($club->isGuestExpired())->toBeFalse();
});

test('a normal club is not a guest and never expires', function () {
    $club = Club::factory()->create();

    expect($club->isGuest())->toBeFalse()->and($club->isGuestExpired())->toBeFalse()->and($club->guest_token_hash)->toBeNull();
});

test('isGuestExpired and the active and expired scopes follow guest_expires_at', function () {
    $active = Club::factory()->guest()->create();
    $expired = Club::factory()->guest(null, now()->subMinute())->create();
    $normal = Club::factory()->create();

    expect($expired->isGuestExpired())->toBeTrue()
        ->and(Club::query()->activeGuests()->pluck('id')->all())->toBe([$active->id])
        ->and(Club::query()->expiredGuests()->pluck('id')->all())->toBe([$expired->id])
        ->and(Club::query()->whereKey($normal->id)->activeGuests()->exists())->toBeFalse();
});

test('the guest token hash is unique', function () {
    Club::factory()->guest('same')->create();

    expect(fn () => Club::factory()->guest('same')->create())->toThrow(UniqueConstraintViolationException::class);
});

test('the guest config has the standard limits', function () {
    expect(config('pickleq.guest'))->toMatchArray([
        'max_courts' => 6, 'max_checked_in' => 40, 'max_roster' => 60, 'max_sessions' => 1,
        'idle_hours' => 24, 'max_hours' => 72, 'starts_per_hour' => 5, 'max_active' => 500,
    ]);
});
