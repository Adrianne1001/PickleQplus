<?php

use App\Models\Club;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without a club are sent to create their first club', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('clubs.create'));
});

test('dashboard redirects to the current club', function () {
    $user = User::factory()->create();
    Club::factory()->withOwner($user)->create();
    $b = Club::factory()->withOwner($user)->create();
    $user->forceFill(['current_club_id' => $b->id])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('clubs.show', $b));
});

test('dashboard falls back to the first club when the current club is stale', function () {
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    $other = Club::factory()->withOwner()->create();
    $user->forceFill(['current_club_id' => $other->id])->save();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('clubs.show', $club));
});
