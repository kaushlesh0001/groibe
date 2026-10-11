<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8" wire:poll.{{ $pollSeconds }}s.visible>
    <div class="flex flex-col gap-2">
        @if ($backUrl)
            <a class="text-sm text-brand-blue underline" href="{{ $backUrl }}" wire:navigate>All messages</a>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="xl" level="1">{{ $thread->subject }}</flux:heading>
            <x-status-badge :status="$thread->type" />
        </div>
        @if ($thread->project && $thread->type === \App\Enums\ThreadType::Project)
            <flux:text>Everyone on this project can read these messages, except notes marked internal.</flux:text>
        @else
            <flux:text>Only you and the Groibe team can read this conversation.</flux:text>
        @endif
    </div>

    <section aria-label="Messages" aria-live="polite" class="flex flex-col gap-3">
        @if ($hasEarlier)
            <div><flux:button size="sm" wire:click="showEarlier">Show earlier messages</flux:button></div>
        @endif

        @forelse ($messages as $message)
            <article wire:key="message-{{ $message->id }}"
                @class([
                    'flex flex-col gap-1 rounded-lg border p-4',
                    'border-amber-400 bg-amber-50' => $message->is_internal,
                    'border-zinc-200' => ! $message->is_internal,
                ])>
                <header class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <span class="font-semibold">{{ $labelFor($message) }}</span>
                    <span class="text-zinc-600">
                        @if ($message->is_internal) Internal note, only Groibe staff can see this &middot; @endif
                        <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->toDayDateTimeString() }}</time>
                    </span>
                </header>
                <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                @php($files = $filesFor($message))
                @if (count($files) > 0)
                    <ul class="mt-1 flex flex-col gap-1 text-sm">
                        @foreach ($files as $file)
                            <li><a class="text-brand-blue underline" href="{{ $file['url'] }}">{{ $file['name'] }}</a> <span class="text-zinc-600">({{ $file['size'] }})</span></li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <flux:text>No messages yet.</flux:text>
        @endforelse
    </section>

    @if ($canReply)
        <form wire:submit="send" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-5">
            <flux:textarea wire:model="body" label="Your message" rows="4" :maxlength="$maxChars" />
            @error('body') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:input type="file" wire:model="uploads" multiple label="Attach files" description="Up to {{ $maxFiles }} files, {{ $maxMb }} MB each. Allowed: {{ $extensions }}." />
            @error('uploads') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($canInternal)
                <flux:checkbox wire:model="internal" label="Internal note" description="Only Groibe staff will see it. Clients and contributors never do." />
            @endif
            <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send,uploads">Send</flux:button></div>
        </form>
    @else
        <flux:text>This conversation is read-only now.</flux:text>
    @endif
</div>
