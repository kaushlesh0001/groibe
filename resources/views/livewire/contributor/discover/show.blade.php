<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <a class="text-sm text-brand-blue underline" href="{{ route('contributor.discover') }}" wire:navigate>Back to Discover</a>
        <flux:heading size="xl" level="1">{{ $role->title }}</flux:heading>
        @if ($role->summary)
            <flux:text>{{ $role->summary }}</flux:text>
        @endif
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ session('error') }}</p>
    @endif

    <x-open-role-body :role="$role" />

    <section aria-labelledby="apply-heading" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-5">
        <flux:heading id="apply-heading" size="lg" level="2">Apply</flux:heading>

        @if ($application && ! $canApply)
            <div class="flex flex-wrap items-center gap-3">
                <span>Your application</span>
                <x-status-badge :status="$application->status" />
            </div>
            <a class="w-fit text-brand-blue underline" href="{{ route('contributor.applications') }}" wire:navigate>See all my applications</a>
        @elseif (! $canApply)
            <flux:text>This role is not taking applications right now.</flux:text>
        @else
            @if ($application)
                <flux:text>You withdrew an earlier application. Applying again replaces it with the answers below.</flux:text>
            @endif

            <form wire:submit="apply" class="flex flex-col gap-4">
                <flux:textarea wire:model="form.motivation" label="Why this role?" rows="5" required />
                <flux:textarea wire:model="form.relevant_experience" label="Relevant experience" rows="4" />
                <flux:input wire:model="form.availability_note" label="Your availability" />
                <flux:input wire:model="form.expected_amount" label="Expected amount in INR (optional)" type="number" min="0" step="0.01" inputmode="decimal" />
                <div>
                    <flux:button type="submit" variant="primary">Send application</flux:button>
                </div>
            </form>
        @endif
    </section>
</div>
