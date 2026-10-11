<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">Messages</flux:heading>
        @if ($canStartSupport)
            <flux:button wire:click="$toggle('composing')" :variant="$composing ? 'ghost' : 'primary'">{{ $composing ? 'Cancel' : 'Message Groibe' }}</flux:button>
        @endif
    </div>

    @if ($composing)
        <form wire:submit="start" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-5">
            <flux:input wire:model="subject" label="Subject" maxlength="120" required />
            @error('subject') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:textarea wire:model="body" label="Your message" rows="4" required />
            @error('body') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:input type="file" wire:model="uploads" multiple label="Attach files" description="Up to {{ $maxFiles }} files, {{ $maxMb }} MB each." />
            @error('uploads') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:text>Only you and the Groibe team can read this.</flux:text>
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="start,uploads">Send</flux:button></div>
        </form>
    @endif

    <ul class="flex flex-col gap-2" aria-label="Conversations">
        @forelse ($threads as $thread)
            <li wire:key="thread-{{ $thread->id }}">
                <a href="{{ $links->for($thread, $user) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 hover:bg-zinc-50">
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate font-semibold">{{ $thread->subject }}</span>
                        <span class="text-sm text-zinc-600">
                            {{ $thread->type === \App\Enums\ThreadType::Project ? 'Project' : 'Support' }}
                            @if ($thread->last_message_at) &middot; last message {{ $thread->last_message_at->diffForHumans() }} @endif
                        </span>
                    </span>
                    @if ($thread->unread_count > 0)
                        <span class="shrink-0 rounded-full bg-brand-navy px-2.5 py-0.5 text-sm text-white">{{ $thread->unread_count }} new</span>
                    @endif
                </a>
            </li>
        @empty
            <li><flux:text>No conversations yet. Project conversations appear here once a Statement of Work is sent.</flux:text></li>
        @endforelse
    </ul>

    {{ $threads->links() }}
</div>
