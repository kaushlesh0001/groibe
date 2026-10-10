<div class="mx-auto flex max-w-4xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Briefs</flux:heading>
        @if ($canCreate)
            <flux:button :href="route('client.briefs.create')" variant="primary" wire:navigate>New brief</flux:button>
        @endif
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-brand-green p-4">{{ session('status') }}</p>
    @endif

    @forelse ($briefs as $brief)
        <a href="{{ route('client.briefs.show', $brief) }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 hover:border-brand-blue" wire:key="brief-{{ $brief->id }}">
            <span class="flex flex-col">
                <span class="font-medium">{{ $brief->title }}</span>
                <span class="text-sm text-zinc-600">{{ $brief->projectType?->name ?? 'No type chosen' }} &middot; updated {{ $brief->updated_at->diffForHumans() }}</span>
            </span>
            <x-status-badge :status="$brief->status" />
        </a>
    @empty
        <flux:text>No briefs yet.@if ($canCreate) Start one to tell us what you need delivered.@endif</flux:text>
    @endforelse

    {{ $briefs->links() }}
</div>
