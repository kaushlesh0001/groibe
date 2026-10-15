<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;

/** Fills a password screen and submits it, with the given password. */
function submitPassword(string $screen, string $password): Testable
{
    $component = match ($screen) {
        'register-contributor' => Volt::test('auth.register-contributor')->set('name', 'Asha Rao')->set('email', 'asha@example.com'),
        'register-client' => Volt::test('auth.register-client')->set('name', 'Meera Sen')->set('email', 'meera@acme.example')->set('organization_name', 'Acme Foods'),
        'invite' => Volt::test('auth.invite', ['token' => User::factory()->contributor()->pending()->create(['password' => null])->uuid]),
        'reset' => Volt::test('auth.reset-password', ['token' => 'a-token'])->set('email', 'asha@example.com'),
        'settings' => Volt::test('settings.password')->set('current_password', 'the-right-password'),
    };

    $component->set('password', $password)->set('password_confirmation', $password);

    return $component->call(match ($screen) {
        'register-contributor', 'register-client' => 'register',
        'invite' => 'activate',
        'reset' => 'resetPassword',
        'settings' => 'updatePassword',
    });
}

describe('password rules, spec 17.2', function () {
    it('asks for at least ten characters everywhere a password is chosen', function (string $screen) {
        if ($screen === 'settings') {
            $this->actingAs(User::factory()->create(['password' => 'the-right-password']));
        }

        submitPassword($screen, 'short-123')->assertHasErrors('password');
        submitPassword($screen, 'abcdefghi')->assertHasErrors('password');
        submitPassword($screen, '')->assertHasErrors('password');
    })->with(['register-contributor', 'register-client', 'invite', 'reset', 'settings']);

    it('accepts ten characters and more', function (string $screen) {
        if ($screen === 'settings') {
            $this->actingAs(User::factory()->create(['password' => 'the-right-password']));
        }

        submitPassword($screen, 'a-long-pass-1')->assertHasNoErrors('password');
    })->with(['register-contributor', 'register-client', 'invite', 'settings']);

    it('requires the confirmation to match', function (string $screen) {
        if ($screen === 'settings') {
            $this->actingAs(User::factory()->create(['password' => 'the-right-password']));
        }

        $component = match ($screen) {
            'register-contributor' => Volt::test('auth.register-contributor')->set('name', 'Asha')->set('email', 'a@example.com'),
            'settings' => Volt::test('settings.password')->set('current_password', 'the-right-password'),
        };

        $component->set('password', 'a-long-pass-1')->set('password_confirmation', 'a-different-1')
            ->call($screen === 'settings' ? 'updatePassword' : 'register')
            ->assertHasErrors('password');
    })->with(['register-contributor', 'settings']);

    it('hashes what it stores', function () {
        Volt::test('auth.register-contributor')->set('name', 'Asha Rao')->set('email', 'asha@example.com')
            ->set('password', 'a-long-pass-1')->set('password_confirmation', 'a-long-pass-1')->call('register');

        $stored = User::query()->where('email', 'asha@example.com')->firstOrFail()->getRawOriginal('password');

        expect($stored)->not->toContain('a-long-pass-1')->and(Hash::check('a-long-pass-1', $stored))->toBeTrue();
    });

    it('checks the current password before it lets one be changed', function () {
        $user = User::factory()->create(['password' => 'the-right-password']);
        $this->actingAs($user);

        Volt::test('settings.password')->set('current_password', 'not-it')->set('password', 'a-long-pass-1')->set('password_confirmation', 'a-long-pass-1')
            ->call('updatePassword')->assertHasErrors('current_password');

        expect(Hash::check('the-right-password', $user->refresh()->password))->toBeTrue();
    });

    it('turns on the compromised-password check in production and not elsewhere', function () {
        $rule = fn () => Password::default();

        expect((new ReflectionProperty(Password::class, 'uncompromised'))->getValue($rule()))->toBeFalse();

        app()->detectEnvironment(fn () => 'production');
        Password::$defaultCallback = null;
        (new ReflectionMethod(AppServiceProvider::class, 'boot'))->invoke(new AppServiceProvider(app()));

        expect((new ReflectionProperty(Password::class, 'uncompromised'))->getValue(Password::default()))->toBeTrue()
            ->and((new ReflectionProperty(Password::class, 'min'))->getValue(Password::default()))->toBe(10);
    });
});
