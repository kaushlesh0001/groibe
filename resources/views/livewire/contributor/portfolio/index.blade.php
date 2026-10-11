<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Portfolio</flux:heading>
        <flux:text>A draft is made for you when a project closes. Describe your contribution, ask for publication, and choose where it may be shown.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ session('error') }}</p>
    @endif

    @forelse ($items as $item)
        <section wire:key="proof-{{ $item->id }}" aria-labelledby="proof-{{ $item->id }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:heading id="proof-{{ $item->id }}" size="lg" level="2">{{ $item->title }}</flux:heading>
                    <flux:text>{{ $item->role_title }}</flux:text>
                </div>
                <x-status-badge :status="$item->status" />
            </div>

            @if ($item->status === \App\Enums\ProofStatus::Draft && $item->project?->showcase_consent === \App\Enums\ShowcaseConsent::None)
                <flux:text>The client has not yet allowed this project to be shown, so it cannot be published for now.</flux:text>
            @elseif ($item->status === \App\Enums\ProofStatus::PendingApproval)
                <flux:text>Waiting for Groibe to approve it.</flux:text>
            @endif

            <div class="flex flex-wrap items-end gap-4">
                @if ($item->status !== \App\Enums\ProofStatus::Hidden)
                    <flux:select wire:change="setVisibility({{ $item->id }}, $event.target.value)" label="Show it to" class="max-w-52">
                        @foreach (\App\Enums\ProofVisibility::cases() as $option)
                            <flux:select.option value="{{ $option->value }}" :selected="$item->visibility === $option">{{ $option->getLabel() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:button :href="route('contributor.portfolio.edit', $item)" wire:navigate>
                    {{ $item->status === \App\Enums\ProofStatus::Draft ? 'Edit and publish' : 'Open' }}
                </flux:button>
            </div>
        </section>
    @empty
        <p class="rounded-lg border border-zinc-200 p-5">Nothing here yet. Your first draft appears when a project you worked on is closed.</p>
    @endforelse

    {{ $items->links() }}
</div>
