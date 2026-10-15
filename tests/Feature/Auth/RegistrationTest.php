<?php

use App\Actions\Onboarding\RegisterAccount;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

it('renders both registration screens', function () {
    $this->get('/register/contributor')->assertOk();
    $this->get('/register/client')->assertOk();
});

it('registers a contributor with a pending status, empty profile and verification email', function () {
    Notification::fake();

    Volt::test('auth.register-contributor')
        ->set('name', 'Asha Rao')
        ->set('email', 'asha@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice', absolute: false));

    $user = User::query()->where('email', 'asha@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Contributor)
        ->and($user->status)->toBe(UserStatus::Pending)
        ->and($user->hasRole('contributor'))->toBeTrue()
        ->and($user->profile->onboarding_state)->toBe(OnboardingState::Registered);

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('registers a client with a pending organization of their own, as its owner', function () {
    Volt::test('auth.register-client')
        ->set('name', 'Ravi Shah')
        ->set('email', 'ravi@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->set('organization_name', 'Shah Traders')
        ->call('register')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'ravi@example.com')->firstOrFail();
    $organization = $user->organizations()->sole();

    expect($user->role)->toBe(UserRole::Client)
        ->and($organization->name)->toBe('Shah Traders')
        ->and($organization->status->value)->toBe('pending')
        ->and($user->organizationRole($organization)->value)->toBe('owner');
});

it('rejects short passwords and duplicate emails', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    Volt::test('auth.register-contributor')
        ->set('name', 'Someone')
        ->set('email', 'taken@example.com')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('register')
        ->assertHasErrors(['email', 'password']);
});

it('throttles repeated sign-up attempts per ip', function () {
    RateLimiter::clear('register:127.0.0.1');

    foreach (range(1, 5) as $i) {
        Volt::test('auth.register-contributor')
            ->set('name', 'Bot')
            ->set('email', "bot{$i}@example.com")
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('register');
    }

    Volt::test('auth.register-contributor')
        ->set('name', 'Bot')
        ->set('email', 'bot6@example.com')
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('register')
        ->assertHasErrors('email');

    expect(User::query()->where('email', 'bot6@example.com')->exists())->toBeFalse();
});

it('hides contributor signup when the flag is off', function () {
    app(FeatureFlags::class)->set(FeatureFlag::ContributorSignup, false);

    $this->get('/register/contributor')->assertNotFound();
    $this->get('/register/client')->assertOk();
});

it('hides client signup when the flag is off', function () {
    app(FeatureFlags::class)->set(FeatureFlag::SelfServeClientSignup, false);

    $this->get('/register/client')->assertNotFound();
});

it('never lets an admin account self-register', function () {
    expect(fn () => app(RegisterAccount::class)(UserRole::Admin, 'X', 'x@example.com', 'a-long-password'))
        ->toThrow(InvalidArgumentException::class);
});
