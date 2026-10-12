<?php

use App\Models\User;
use App\Support\Throttle;
use Filament\Auth\Pages\Login as AdminLogin;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Volt\Volt;

describe('the Throttle helper', function () {
    it('allows the maximum and refuses the next, with a message on the field', function () {
        foreach (range(1, 3) as $_) {
            Throttle::guard('test', 'subject', 3, 60, 'email');
        }

        expect(fn () => Throttle::guard('test', 'subject', 3, 60, 'email'))
            ->toThrow(ValidationException::class, 'Too many attempts');
    });

    it('counts each subject and each scope separately', function () {
        foreach (range(1, 3) as $_) {
            Throttle::guard('test', 'one', 3, 60, 'email');
        }

        Throttle::guard('test', 'two', 3, 60, 'email');
        Throttle::guard('other', 'one', 3, 60, 'email');

        expect(Throttle::allow('test', 'two', 3, 60))->toBeTrue();
    });

    it('treats case differences as the same subject, so changing case is no way round it', function () {
        foreach (range(1, 3) as $_) {
            Throttle::guard('test', 'Asha@Example.com', 3, 60, 'email');
        }

        expect(Throttle::allow('test', 'asha@example.com', 3, 60))->toBeFalse();
    });

    it('forgets the count when told, and lets the window run out', function () {
        foreach (range(1, 3) as $_) {
            Throttle::guard('test', 'subject', 3, 60, 'email');
        }

        Throttle::clear('test', 'subject');

        expect(Throttle::allow('test', 'subject', 3, 60))->toBeTrue();

        foreach (range(1, 2) as $_) {
            Throttle::allow('test', 'subject', 3, 60);
        }
        expect(Throttle::allow('test', 'subject', 3, 60))->toBeFalse();

        $this->travel(61)->seconds();
        expect(Throttle::allow('test', 'subject', 3, 60))->toBeTrue();
    });
});

describe('sign-in and account screens', function () {
    it('limits password reset requests per address and email, and per address overall', function () {
        foreach (range(1, 5) as $_) {
            Volt::test('auth.forgot-password')->set('email', 'asha@example.com')->call('sendPasswordResetLink')->assertHasNoErrors();
        }

        Volt::test('auth.forgot-password')->set('email', 'asha@example.com')->call('sendPasswordResetLink')->assertHasErrors('email');

        // A different email from the same address has its own count, but the address-wide cap of 20 an hour still applies.
        foreach (range(1, 15) as $i) {
            Volt::test('auth.forgot-password')->set('email', "other{$i}@example.com")->call('sendPasswordResetLink')->assertHasNoErrors();
        }

        Volt::test('auth.forgot-password')->set('email', 'another@example.com')->call('sendPasswordResetLink')->assertHasErrors('email');
    });

    it('says the same thing for an account that exists and one that does not', function () {
        User::factory()->create(['email' => 'real@example.com']);

        $real = Volt::test('auth.forgot-password')->set('email', 'real@example.com')->call('sendPasswordResetLink');
        $missing = Volt::test('auth.forgot-password')->set('email', 'missing@example.com')->call('sendPasswordResetLink');

        $real->assertHasNoErrors()->assertSee('A reset link will be sent if the account exists.');
        $missing->assertHasNoErrors()->assertSee('A reset link will be sent if the account exists.');
    });

    it('limits attempts at a reset token', function () {
        foreach (range(1, 10) as $_) {
            Volt::test('auth.reset-password', ['token' => 'wrong'])
                ->set('email', 'asha@example.com')->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
                ->call('resetPassword');
        }

        Volt::test('auth.reset-password', ['token' => 'wrong'])
            ->set('email', 'asha@example.com')->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
            ->call('resetPassword')
            ->assertHasErrors('email');
    });

    it('limits guesses at the password on the confirm screen, and a success starts the count again', function () {
        $user = User::factory()->create(['password' => 'the-right-password']);
        $this->actingAs($user);

        foreach (range(1, 5) as $_) {
            Volt::test('auth.confirm-password')->set('password', 'wrong')->call('confirmPassword')->assertHasErrors('password');
        }

        // The sixth is refused as a rate limit even with the right password.
        $blocked = Volt::test('auth.confirm-password')->set('password', 'the-right-password')->call('confirmPassword');
        $blocked->assertHasErrors('password');
        expect(session('auth.password_confirmed_at'))->toBeNull();

        $this->travel(61)->seconds();
        Volt::test('auth.confirm-password')->set('password', 'the-right-password')->call('confirmPassword')->assertHasNoErrors();
        expect(session('auth.password_confirmed_at'))->not->toBeNull();
    });

    it('limits guesses at the current password when changing it', function () {
        $user = User::factory()->create(['password' => 'the-right-password']);
        $this->actingAs($user);

        foreach (range(1, 5) as $_) {
            Volt::test('settings.password')->set('current_password', 'wrong')->set('password', 'a-new-long-password')->set('password_confirmation', 'a-new-long-password')
                ->call('updatePassword')->assertHasErrors('current_password');
        }

        Volt::test('settings.password')->set('current_password', 'the-right-password')->set('password', 'a-new-long-password')->set('password_confirmation', 'a-new-long-password')
            ->call('updatePassword')->assertHasErrors('current_password');

        expect(Hash::check('the-right-password', $user->refresh()->password))->toBeTrue();
    });

    it('keeps one person\'s attempts from blocking another', function () {
        $first = User::factory()->create(['password' => 'the-right-password']);
        $second = User::factory()->create(['password' => 'the-right-password']);

        $this->actingAs($first);
        foreach (range(1, 6) as $_) {
            Volt::test('auth.confirm-password')->set('password', 'wrong')->call('confirmPassword');
        }

        asUser($second);
        Volt::test('auth.confirm-password')->set('password', 'the-right-password')->call('confirmPassword')->assertHasNoErrors();
    });

    it('sends three verification emails and then the same confirmation without another', function () {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        foreach (range(1, 6) as $_) {
            $page = Volt::test('auth.verify-email')->call('sendVerification');

            // Every press shows the same confirmation, so the page never reveals that the limit was reached.
            $page->assertSee('A new verification link has been sent');
        }

        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    });

    it('limits resends from the profile page the same way, sharing the count', function () {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        Volt::test('auth.verify-email')->call('sendVerification')->call('sendVerification');
        foreach (range(1, 4) as $_) {
            Volt::test('settings.profile')->call('resendVerificationNotification');
        }

        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    });

    it('limits profile and email changes per person', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (range(1, 10) as $i) {
            Volt::test('settings.profile')->set('name', "Name {$i}")->set('email', $user->email)->call('updateProfileInformation')->assertHasNoErrors();
        }

        Volt::test('settings.profile')->set('name', 'Name 11')->set('email', $user->email)->call('updateProfileInformation')->assertHasErrors('email');
    });
});

describe('routes', function () {
    it('limits the contact form to three a minute per address', function () {
        foreach (range(1, 3) as $_) {
            $this->post('/contact', ['name' => 'Asha', 'email' => 'asha@example.com', 'message' => 'Hello there, I have a question.'])->assertStatus(302);
        }

        $this->post('/contact', ['name' => 'Asha', 'email' => 'asha@example.com', 'message' => 'Hello there, I have a question.'])->assertStatus(429);
    });

    it('limits invite redemption to ten a minute per address', function () {
        $url = URL::temporarySignedRoute('invite.show', now()->addDay(), ['token' => 'not-a-real-token']);

        foreach (range(1, 10) as $_) {
            $this->get($url);
        }

        $this->get($url)->assertStatus(429);
    });

    it('limits the email verification link', function () {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => 'wrong']);

        $this->actingAs($user);

        foreach (range(1, 6) as $_) {
            $this->get($url);
        }

        $this->get($url)->assertStatus(429);
    });

    it('limits private file downloads', function () {
        $route = Route::getRoutes()->getByName('files.show');

        expect($route->gatherMiddleware())->toContain('throttle:60,1');
    });
});

describe('Livewire endpoints', function () {
    it('puts one limit on the endpoint that every Livewire action and refresh uses', function () {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'livewire.update');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('throttle:livewire');
    });

    it('counts a signed-in person by account and a visitor by address', function () {
        $limiter = RateLimiter::limiter('livewire');
        $user = User::factory()->create();

        $signedIn = Request::create('/livewire/update', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $signedIn->setUserResolver(fn () => $user);
        $visitor = Request::create('/livewire/update', 'POST', server: ['REMOTE_ADDR' => '10.0.0.2']);

        $account = $limiter($signedIn);
        $guest = $limiter($visitor);

        expect($account->maxAttempts)->toBe(240)->and($account->key)->toBe($user->getAuthIdentifier())
            ->and($guest->maxAttempts)->toBe(240)->and($guest->key)->toBe('10.0.0.2');
    });

    it('limits temporary uploads to 30 a minute', function () {
        expect(config('livewire.temporary_file_upload.middleware'))->toBe('throttle:30,1');
    });
});

describe('the admin sign-in', function () {
    it('locks out after repeated wrong passwords', function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = adminUser();

        $page = Livewire::test(AdminLogin::class);

        foreach (range(1, 5) as $_) {
            $page->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])->call('authenticate');
        }

        $page->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])->call('authenticate')->assertNotified();

        expect(auth()->check())->toBeFalse();
    });
});
