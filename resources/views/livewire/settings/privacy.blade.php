<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Privacy and your data" subheading="Ask for a copy of your data, or ask us to erase it">
        <div class="flex flex-col gap-8">
            <p class="text-sm text-zinc-700">
                Groibe keeps only what it needs to run your work with us. You can ask for a copy of it, or ask us to erase it. A Groibe admin answers every request. Nothing changes until they do.
            </p>

            @if ($sent)
                <p role="status" class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm">{{ $sent }}</p>
            @endif

            @if ($problem)
                <p role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{{ $problem }}</p>
            @endif

            @error('request')
                <p role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{{ $message }}</p>
            @enderror

            <div class="flex flex-col gap-3">
                <flux:button type="button" wire:click="requestExport">Ask for a copy of my data</flux:button>
                <flux:button type="button" variant="danger" wire:click="requestDeletion"
                    wire:confirm="Ask Groibe to erase your data? Your account will be closed and you will not be able to sign in again. Payments and delivered work are kept without your name.">
                    Ask for my data to be erased
                </flux:button>
            </div>

            <section aria-labelledby="your-requests">
                <h2 id="your-requests" class="text-base font-semibold">Your requests</h2>

                @forelse ($requests as $item)
                    <div wire:key="request-{{ $item->id }}" class="mt-3 border-t border-zinc-200 pt-3 text-sm">
                        <p class="font-medium">
                            {{ $item->type->describe() }}
                            <span class="font-normal text-zinc-600"> &middot; {{ $item->status->getLabel() }} &middot; asked {{ $item->created_at->toFormattedDateString() }}</span>
                        </p>
                        @if ($item->note)
                            <p class="mt-1 text-zinc-700"><span class="font-medium">Message from Groibe:</span> {{ $item->note }}</p>
                        @endif
                    </div>
                @empty
                    <p class="mt-2 text-sm text-zinc-600">You have not made a request.</p>
                @endforelse
            </section>

            <section aria-labelledby="your-agreements">
                <h2 id="your-agreements" class="text-base font-semibold">Agreements you accepted</h2>
                <p class="mt-1 text-sm text-zinc-600">Groibe keeps the date you accepted each version.</p>

                @forelse ($agreements as $acceptance)
                    <p wire:key="agreement-{{ $acceptance->id }}" class="mt-2 text-sm">
                        {{ $acceptance->template->title }} (version {{ $acceptance->template->version }})
                        <span class="text-zinc-600">&middot; accepted {{ $acceptance->accepted_at->toDayDateTimeString() }}</span>
                    </p>
                @empty
                    <p class="mt-2 text-sm text-zinc-600">You have not accepted an agreement yet.</p>
                @endforelse
            </section>
        </div>
    </x-settings.layout>
</div>
