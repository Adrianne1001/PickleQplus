<?php

use App\Models\User;

function registrationPayload(int $i, array $extra = []): array
{
    return array_merge([
        'name' => 'User '.$i,
        'email' => "user{$i}@example.com",
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $extra);
}

test('registration is limited to 5 attempts per hour per IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('register.store'), registrationPayload($i))->assertRedirect();
        auth()->logout();
    }

    $this->post(route('register.store'), registrationPayload(6))->assertStatus(429);
    expect(User::query()->count())->toBe(5);
});

test('the registration limit is per IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('register.store'), registrationPayload($i))->assertRedirect();
        auth()->logout();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('register.store'), registrationPayload(6))->assertRedirect();
});

test('a filled honeypot field is rejected', function () {
    $this->post(route('register.store'), registrationPayload(1, ['homepage_url' => 'http://spam.example']))
        ->assertSessionHasErrors('homepage_url');

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

test('the registration form contains an accessible hidden honeypot', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('name="homepage_url"', false)
        ->assertSee('tabindex="-1"', false)
        ->assertSee('autocomplete="off"', false)
        ->assertSee('Leave this field empty');
});

test('an empty honeypot passes', function () {
    $this->post(route('register.store'), registrationPayload(1, ['homepage_url' => '']))
        ->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe(1);
});
