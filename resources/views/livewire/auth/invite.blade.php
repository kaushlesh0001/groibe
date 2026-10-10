<?php

use App\Actions\Onboarding\ActivateInvitedUser;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        abort_unless($this->invitee() !== null, 404);
    }

    public function activate(ActivateInvitedUser $activate): void
    {
        $key = 'invite:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'password' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $validated = $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->invitee();
        abort_unless($user !== null, 404);

        $activate($user, $validated['password']);

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    private function invitee(): ?User
    {
        return User::query()
            ->where('uuid', $this->token)
            ->where('status', UserStatus::Pending)
            ->whereNull('password')
            ->first();
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Activate your account" description="Choose a password to finish setting up your Groibe account." />

    <form wire:submit="activate" class="flex flex-col gap-6">
        <flux:input wire:model="password" label="{{ __('Password') }}" type="password" required autofocus autocomplete="new-password" description="At least 10 characters." />
        <flux:input wire:model="password_confirmation" label="{{ __('Confirm password') }}" type="password" required autocomplete="new-password" />

        <flux:button type="submit" variant="primary" class="w-full">{{ __('Activate account') }}</flux:button>
    </form>
</div>
