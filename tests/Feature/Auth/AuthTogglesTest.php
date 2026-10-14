<?php

use App\Models\Club;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;

/*
| P13 auth feature toggles. config/fortify.php gates Features::emailVerification() and
| Features::passkeys() from env; the Fortify feature list is the only source of truth, so
| these tests flip config('fortify.features') at runtime to cover the off state. phpunit.xml
| turns both flags on so the Fortify routes register at boot (the routes themselves, and the
| .well-known route, can't be removed at runtime).
*/

function setFortifyFeatures(bool $emailVerification, bool $passkeys): void
{
    config(['fortify.features' => array_values(array_filter([
        Features::registration(),
        Features::resetPasswords(),
        $emailVerification ? Features::emailVerification() : null,
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        $passkeys ? Features::passkeys(['confirmPassword' => true]) : null,
    ]))]);
}

test('with verification off an unverified user counts as verified', function () {
    setFortifyFeatures(emailVerification: false, passkeys: true);

    expect(User::factory()->unverified()->create()->hasVerifiedEmail())->toBeTrue();
});

test('with verification on an unverified user is unverified', function () {
    setFortifyFeatures(emailVerification: true, passkeys: true);

    expect(User::factory()->unverified()->create()->hasVerifiedEmail())->toBeFalse()
        ->and(User::factory()->create()->hasVerifiedEmail())->toBeTrue();
});

test('with verification off no verification email is sent on registration', function () {
    setFortifyFeatures(emailVerification: false, passkeys: true);
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    Notification::assertNothingSent();
});

test('with verification on registration sends the verification email', function () {
    setFortifyFeatures(emailVerification: true, passkeys: true);
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Notification::assertSentTo(User::where('email', 'test@example.com')->firstOrFail(), VerifyEmail::class);
});

test('with verification off an unverified user can reach the app and create a club', function () {
    setFortifyFeatures(emailVerification: false, passkeys: true);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('clubs.create'));
    $this->actingAs($user)->get(route('clubs.create'))->assertOk();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk()
        ->assertDontSee('Your email address is unverified.');

    Livewire::actingAs($user)->test('pages::clubs.create')
        ->set('name', 'Unverified Club')
        ->call('create')
        ->assertHasNoErrors();

    $club = Club::where('name', 'Unverified Club')->firstOrFail();
    $this->actingAs($user)->get(route('clubs.show', $club))->assertOk();
});

test('with verification on an unverified user is sent to the verify screen', function () {
    setFortifyFeatures(emailVerification: true, passkeys: true);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('clubs.create'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->get(route('profile.edit'))->assertOk()
        ->assertSee('Your email address is unverified.');
});

test('the login page hides the passkey sign-in when passkeys are off', function () {
    setFortifyFeatures(emailVerification: true, passkeys: false);

    $this->get(route('login'))->assertOk()->assertDontSee('Sign in with a passkey');
});

test('the login page shows the passkey sign-in when passkeys are on', function () {
    setFortifyFeatures(emailVerification: true, passkeys: true);

    $this->get(route('login'))->assertOk()->assertSee('Sign in with a passkey');
});

test('the confirm password page hides the passkey option when passkeys are off', function () {
    setFortifyFeatures(emailVerification: true, passkeys: false);

    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertDontSee('Confirm with passkey');
});

test('the security settings page hides the passkeys section when passkeys are off', function () {
    setFortifyFeatures(emailVerification: true, passkeys: false);

    Livewire::actingAs(User::factory()->create())->test('pages::settings.security')
        ->assertSet('canManagePasskeys', false)
        ->assertDontSee('Manage your passkeys for passwordless sign-in');
});

test('the security settings page shows the passkeys section when passkeys are on', function () {
    setFortifyFeatures(emailVerification: true, passkeys: true);

    Livewire::actingAs(User::factory()->create())->test('pages::settings.security')
        ->assertSet('canManagePasskeys', true)
        ->assertSee('Manage your passkeys for passwordless sign-in');
});

test('the fortify config only enables the toggles for truthy env values', function () {
    $vars = ['PICKLEQ_EMAIL_VERIFICATION', 'PICKLEQ_PASSKEYS'];
    $original = [];
    foreach ($vars as $var) {
        $original[$var] = [
            'env' => $_ENV[$var] ?? null,
            'server' => $_SERVER[$var] ?? null,
            'putenv' => getenv($var),
        ];
    }

    $setEnv = function (string $var, ?string $value): void {
        if ($value === null) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);

            return;
        }

        putenv("{$var}={$value}");
        $_ENV[$var] = $value;
        $_SERVER[$var] = $value;
    };

    $features = function (): array {
        $config = require base_path('config/fortify.php');

        return $config['features'];
    };

    try {
        foreach (['false', 'off', '0', null] as $value) {
            foreach ($vars as $var) {
                $setEnv($var, $value);
            }

            expect($features())
                ->not->toContain('email-verification')
                ->not->toContain('passkeys')
                ->toContain('registration');
        }

        foreach (['true', 'on', '1'] as $value) {
            foreach ($vars as $var) {
                $setEnv($var, $value);
            }

            expect($features())->toContain('email-verification')->toContain('passkeys');
        }
    } finally {
        foreach ($vars as $var) {
            $saved = $original[$var];

            if ($saved['putenv'] === false) {
                putenv($var);
            } else {
                putenv("{$var}={$saved['putenv']}");
            }

            if ($saved['env'] === null) {
                unset($_ENV[$var]);
            } else {
                $_ENV[$var] = $saved['env'];
            }

            if ($saved['server'] === null) {
                unset($_SERVER[$var]);
            } else {
                $_SERVER[$var] = $saved['server'];
            }
        }
    }
});
