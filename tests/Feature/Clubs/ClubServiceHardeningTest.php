<?php

use App\Domain\Stars\StarRating;
use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Services\ClubService;

test('addMember never changes the role of an existing member', function () {
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    app(ClubService::class)->addMember($club, $owner, ClubRole::Staff);

    expect($owner->roleIn($club))->toBe(ClubRole::Owner);
});

test('normalize lowercases and trims the slug', function () {
    expect(ClubService::normalize(['slug' => '  My-Club  ', 'name' => ' X ']))
        ->toBe(['slug' => 'my-club', 'name' => 'X']);
});

test('removeMember resets current_club_id even when the passed model is stale', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();
    User::query()->whereKey($staff->id)->update(['current_club_id' => $club->id]);

    // The in-memory model does not know about the current club.
    app(ClubService::class)->removeMember($club, $staff);

    expect($staff->fresh()?->current_club_id)->toBeNull();
});

test('removeMember leaves current_club_id alone when another club is current', function () {
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner()->withStaff($staff)->create();
    $other = Club::factory()->withOwner()->withStaff($staff)->create();
    User::query()->whereKey($staff->id)->update(['current_club_id' => $other->id]);

    app(ClubService::class)->removeMember($club, $staff);

    expect($staff->fresh()?->current_club_id)->toBe($other->id);
});

test('the default star bands config comes from StarRating', function () {
    expect(config('pickleq.star_bands'))->toBe(StarRating::DEFAULT_BANDS)
        ->and(config('pickleq.import_preview_ttl_minutes'))->toBe(30);
});
