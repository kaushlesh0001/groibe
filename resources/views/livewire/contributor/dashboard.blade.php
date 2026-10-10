<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Welcome, {{ auth()->user()->name }}</flux:heading>
        <flux:text>Your contributor workspace.</flux:text>
    </div>

    <section aria-labelledby="status-heading" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
        <div class="flex items-center justify-between gap-3">
            <flux:heading id="status-heading" size="lg" level="2">Onboarding status</flux:heading>
            <x-status-badge :status="$state" />
        </div>

        @include('contributor.partials.status-message', ['state' => $state, 'suspended' => auth()->user()->status === \App\Enums\UserStatus::Suspended])

        @if (! $emailVerified)
            <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-3">
                Your email address is not verified yet. <a class="text-brand-blue underline" href="{{ route('verification.notice') }}">Verify it</a> to submit your profile.
            </p>
        @endif

        <div class="flex flex-wrap gap-3">
            @if (in_array($state, [\App\Enums\OnboardingState::Registered, \App\Enums\OnboardingState::ProfileComplete], true))
                <flux:button variant="primary" :href="route('contributor.onboarding')" wire:navigate>Continue onboarding</flux:button>
            @endif
            <flux:button :href="route('contributor.profile')" wire:navigate>Edit profile</flux:button>
        </div>
    </section>
</div>
