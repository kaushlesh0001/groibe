<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8">
    <flux:heading size="xl" level="1">Client MSA</flux:heading>

    @if ($organization === null)
        <flux:text>Your account is not linked to an organization yet. Please contact Groibe.</flux:text>
    @elseif ($template === null)
        <flux:text>The Client MSA has not been published yet. Please contact Groibe.</flux:text>
    @else
        <flux:text>{{ $template->title }}, version {{ $template->version }}</flux:text>

        <article class="prose max-w-none rounded-lg border border-zinc-200 p-4">
            {{-- Admin-authored agreement text. --}}
            {!! $template->body_html !!}
        </article>

        @if ($alreadyAccepted)
            <p role="status" class="rounded-lg border border-brand-green p-4">
                The Client MSA is accepted for {{ $organization->name }}.
            </p>
        @elseif (! $isOwner)
            <p role="status" class="rounded-lg border border-zinc-300 p-4">
                Only the organization owner can accept the Client MSA. Please ask your owner to sign in and accept it.
            </p>
        @else
            <form wire:submit="accept" class="flex flex-col gap-4">
                <flux:checkbox wire:model="confirmed" label="I have read the agreement and accept it on behalf of {{ $organization->name }}." />
                <flux:error name="confirmed" />
                <div>
                    <flux:button type="submit" variant="primary">Accept Client MSA</flux:button>
                </div>
            </form>
        @endif
    @endif
</div>
