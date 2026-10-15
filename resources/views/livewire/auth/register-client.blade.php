<?php

use App\Actions\Onboarding\RegisterClient;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $designation = '';
    public string $organization_name = '';
    public string $legal_name = '';
    public string $gstin = '';
    public string $website = '';
    public string $industry = '';
    public string $size_band = '';
    public string $city = '';
    public string $state = '';
    public string $country = 'India';

    public function register(RegisterClient $registerClient): void
    {
        $key = 'register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many sign-up attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $this->gstin = strtoupper(trim($this->gstin));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'designation' => ['nullable', 'string', 'max:255'],
            'organization_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // The message never names the organization that holds the GSTIN: a stranger learns nothing about it from this form.
            'gstin' => ['nullable', 'regex:/^[0-9A-Z]{15}$/', Rule::unique('organizations', 'gstin')],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'size_band' => ['nullable', Rule::in(config('groibe.organizations.size_bands'))],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
        ], [
            'gstin.regex' => 'The GSTIN must be 15 letters and digits.',
            'gstin.unique' => 'This GSTIN is already registered with Groibe. Contact Groibe to be added to your organization.',
        ]);

        $user = $registerClient([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'designation' => $validated['designation'] ?? null,
            'organization' => [
                'name' => $validated['organization_name'],
                'legal_name' => $validated['legal_name'] ?? null,
                'gstin' => $validated['gstin'] ?? null,
                'website' => $validated['website'] ?? null,
                'industry' => $validated['industry'] ?? null,
                'size_band' => $validated['size_band'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'country' => $validated['country'],
            ],
        ]);

        Auth::login($user);

        $this->redirect(route('verification.notice', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Register as a client" description="Create your account and tell us about your organization. Groibe checks the details before your organization can submit a brief." />

    <form wire:submit="register" class="flex flex-col gap-6">
        <fieldset class="flex flex-col gap-6">
            <legend class="mb-2 text-base font-semibold">{{ __('About you') }}</legend>
            <flux:input wire:model="name" label="{{ __('Full name') }}" type="text" required autofocus autocomplete="name" />
            <flux:input wire:model="email" label="{{ __('Work email') }}" type="email" required autocomplete="email" placeholder="you@company.com" />
            <flux:input wire:model="password" label="{{ __('Password') }}" type="password" required autocomplete="new-password" description="At least 10 characters." />
            <flux:input wire:model="password_confirmation" label="{{ __('Confirm password') }}" type="password" required autocomplete="new-password" />
            <flux:input wire:model="designation" label="{{ __('Your role in the organization') }}" type="text" autocomplete="organization-title" placeholder="Founder" />
        </fieldset>

        <fieldset class="flex flex-col gap-6">
            <legend class="mb-2 text-base font-semibold">{{ __('Your organization') }}</legend>
            <flux:input wire:model="organization_name" label="{{ __('Organization name') }}" type="text" required autocomplete="organization" />
            <flux:input wire:model="legal_name" label="{{ __('Registered legal name') }}" type="text" description="If different from the name above." />
            <flux:input wire:model="gstin" label="{{ __('GSTIN') }}" type="text" maxlength="15" description="Optional. 15 letters and digits." />
            <flux:input wire:model="website" label="{{ __('Website') }}" type="url" placeholder="https://" autocomplete="url" />
            <flux:input wire:model="industry" label="{{ __('Industry') }}" type="text" />
            <flux:select wire:model="size_band" label="{{ __('Number of people') }}">
                <flux:select.option value="">{{ __('Choose one') }}</flux:select.option>
                @foreach (config('groibe.organizations.size_bands') as $band)
                    <flux:select.option value="{{ $band }}">{{ $band }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="city" label="{{ __('City') }}" type="text" autocomplete="address-level2" />
            <flux:input wire:model="state" label="{{ __('State') }}" type="text" autocomplete="address-level1" />
            <flux:input wire:model="country" label="{{ __('Country') }}" type="text" required autocomplete="country-name" />
        </fieldset>

        <flux:button type="submit" variant="primary" class="w-full">{{ __('Create account') }}</flux:button>
    </form>

    <div class="text-center text-sm text-zinc-600">
        Already have an account? <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
