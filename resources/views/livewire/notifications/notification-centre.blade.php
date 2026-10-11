<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">Notifications</flux:heading>
        <div class="flex gap-2">
            <flux:button size="sm" :href="route('settings.notifications')" wire:navigate>Preferences</flux:button>
            @if ($unread > 0)
                <flux:button size="sm" wire:click="markAllRead">Mark all read</flux:button>
            @endif
        </div>
    </div>

    <ul class="flex flex-col gap-2" aria-label="Notifications">
        @forelse ($notifications as $notification)
            @php($data = $notification->data)
            <li wire:key="n-{{ $notification->id }}" @class(['flex items-start justify-between gap-3 rounded-lg border p-4', 'border-brand-blue bg-blue-50' => $notification->read_at === null, 'border-zinc-200' => $notification->read_at !== null])>
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="font-semibold">
                        @if ($notification->read_at === null) <span class="sr-only">Unread: </span> @endif
                        {{ $data['title'] ?? class_basename($notification->type) }}
                    </span>
                    @if (! empty($data['body'])) <span class="text-sm">{{ $data['body'] }}</span> @endif
                    <time class="text-sm text-zinc-600" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                </div>
                <div class="flex shrink-0 gap-2">
                    @if (! empty($data['url']))
                        <flux:button size="sm" wire:click="open('{{ $notification->id }}')">Open</flux:button>
                    @endif
                    @if ($notification->read_at === null)
                        <flux:button size="sm" variant="ghost" wire:click="markRead('{{ $notification->id }}')">Mark read</flux:button>
                    @endif
                </div>
            </li>
        @empty
            <li><flux:text>Nothing yet. Updates about your work with Groibe will appear here.</flux:text></li>
        @endforelse
    </ul>

    {{ $notifications->links() }}
</div>
