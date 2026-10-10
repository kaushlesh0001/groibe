<x-layouts.app title="Account status">
    <div class="mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-8">
        <flux:heading size="xl" level="1">This area is not available yet</flux:heading>
        <div role="status" class="rounded-lg border border-zinc-200 p-5">
            @include('contributor.partials.status-message', ['state' => $state, 'suspended' => $suspended])
        </div>
        <div>
            <flux:button :href="route('contributor.dashboard')">Back to your dashboard</flux:button>
        </div>
    </div>
</x-layouts.app>
