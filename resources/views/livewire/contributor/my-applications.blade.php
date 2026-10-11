<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">My applications</flux:heading>
        <flux:text>Where each application stands.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ session('error') }}</p>
    @endif

    @forelse ($applications as $application)
        <section wire:key="application-{{ $application->id }}" aria-labelledby="application-{{ $application->id }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:heading id="application-{{ $application->id }}" size="lg" level="2">{{ $application->openRole->title }}</flux:heading>
                    <flux:text>Applied {{ $application->created_at->toFormattedDateString() }}</flux:text>
                </div>
                <x-status-badge :status="$application->status" />
            </div>

            @if ($application->status === \App\Enums\ApplicationStatus::Offered)
                <flux:text>Groibe has made you an offer for this role. Accepting puts you on the team.</flux:text>
                <div class="flex flex-wrap gap-3">
                    <flux:button variant="primary" wire:click="accept({{ $application->id }})" wire:confirm="Accept this offer?">Accept offer</flux:button>
                    <flux:button wire:click="decline({{ $application->id }})" wire:confirm="Decline this offer?">Decline</flux:button>
                </div>
            @elseif (in_array($application->status, [\App\Enums\ApplicationStatus::Submitted, \App\Enums\ApplicationStatus::Shortlisted], true))
                <div>
                    <flux:button wire:click="withdraw({{ $application->id }})" wire:confirm="Withdraw this application?">Withdraw</flux:button>
                </div>
            @endif

            <a class="w-fit text-brand-blue underline" href="{{ route('contributor.discover.show', $application->openRole->slug) }}" wire:navigate>View the role</a>
        </section>
    @empty
        <p class="rounded-lg border border-zinc-200 p-5">You have not applied to any role yet. <a class="text-brand-blue underline" href="{{ route('contributor.discover') }}" wire:navigate>Browse open roles</a>.</p>
    @endforelse

    {{ $applications->links() }}
</div>
