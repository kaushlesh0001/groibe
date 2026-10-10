<div class="mx-auto flex max-w-4xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Deliverables</flux:heading>
        <flux:text>Work our team has checked and shared with you. Accept it, or tell us what to change.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @error('response') <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p> @enderror

    @forelse ($milestones as $milestone)
        <section wire:key="milestone-{{ $milestone->id }}" aria-labelledby="milestone-{{ $milestone->id }}" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:heading id="milestone-{{ $milestone->id }}" size="lg" level="2">{{ $milestone->title }}</flux:heading>
                    <flux:text>
                        <a class="text-brand-blue underline" href="{{ route('client.projects.show', $milestone->project) }}" wire:navigate>{{ $milestone->project->title }}</a>
                        @if ($milestone->shared_at) &middot; shared {{ $milestone->shared_at->toFormattedDateString() }} @endif
                    </flux:text>
                </div>
                <x-status-badge :status="$milestone->status" />
            </div>

            @if ($milestone->description) <p class="whitespace-pre-line">{{ $milestone->description }}</p> @endif

            <ul class="flex flex-col gap-3">
                @foreach ($milestone->deliverables as $deliverable)
                    <li wire:key="deliverable-{{ $deliverable->id }}" class="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
                        <p class="font-medium">{{ $deliverable->title }}</p>
                        @if ($deliverable->summary) <p class="whitespace-pre-line text-sm">{{ $deliverable->summary }}</p> @endif
                        @if ($files[$deliverable->id] !== [])
                            <ul class="mt-2 list-disc pl-5 text-sm">
                                @foreach ($files[$deliverable->id] as $file)
                                    <li><a class="text-brand-blue underline" href="{{ $file['url'] }}">{{ $file['name'] }}</a> <span class="text-zinc-600">({{ $file['size'] }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($milestone->status === \App\Enums\MilestoneStatus::ClientChangesRequested && $milestone->client_note)
                <p class="rounded-lg border border-amber-400 bg-amber-50 p-3"><strong>Your note:</strong> {{ $milestone->client_note }}</p>
                <flux:text>Our team is working on your changes and will share this again when it is ready.</flux:text>
            @endif

            @if ($milestone->status === \App\Enums\MilestoneStatus::Shared)
                @if ($mayRespond)
                    @if ($changesFor === $milestone->id)
                        <form wire:submit="sendChanges" class="flex flex-col gap-3">
                            <flux:textarea wire:model="changeNote" label="What needs to change?" rows="4" required />
                            <div class="flex gap-3">
                                <flux:button type="submit" variant="primary">Send changes</flux:button>
                                <flux:button type="button" wire:click="cancelChanges">Cancel</flux:button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap gap-3">
                            <flux:button variant="primary" wire:click="accept({{ $milestone->id }})" wire:confirm="Accept this milestone? This tells the team the work is complete.">Accept</flux:button>
                            <flux:button wire:click="startChanges({{ $milestone->id }})">Request changes</flux:button>
                        </div>
                    @endif
                @else
                    <flux:text>Only organization owners and managers can accept or request changes.</flux:text>
                @endif
            @endif
        </section>
    @empty
        <p class="rounded-lg border border-zinc-200 p-5">Nothing to review yet. When our team shares finished work, it appears here.</p>
    @endforelse

    {{ $milestones->links() }}
</div>
