<?php

use App\Actions\Onboarding\RegisterAccount;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function register(RegisterAccount $registerAccount): void
    {
        $key = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many sign-up attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $registerAccount(UserRole::Client, $validated['name'], $validated['email'], $validated['password']);

        Auth::login($user);

        $this->redirect(route('verification.notice', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Register as a client" description="Create your account. Organization details and the client agreement come next." />

    <form wire:submit="register" class="flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Full name') }}" type="text" required autofocus autocomplete="name" />
        <flux:input wire:model="email" label="{{ __('Work email') }}" type="email" required autocomplete="email" placeholder="you@company.com" />
        <flux:input wire:model="password" label="{{ __('Password') }}" type="password" required autocomplete="new-password" description="At least 10 characters." />
        <flux:input wire:model="password_confirmation" label="{{ __('Confirm password') }}" type="password" required autocomplete="new-password" />

        <flux:button type="submit" variant="primary" class="w-full">{{ __('Create account') }}</flux:button>
    </form>

    <div class="text-center text-sm text-zinc-600">
        Already have an account? <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
