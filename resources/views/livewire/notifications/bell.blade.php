<div wire:poll.60s.visible>
    <a href="{{ route('notifications.index') }}"
        aria-label="Notifications{{ $count > 0 ? ', '.$count.' unread' : '' }}"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-brand-navy hover:bg-zinc-100">
        <flux:icon.bell class="size-5" />
        @if ($count > 0)
            <span class="absolute -right-0.5 -top-0.5 min-w-5 rounded-full bg-brand-navy px-1 text-center text-xs leading-5 text-white">{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </a>
</div>
