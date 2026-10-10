<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">My work</flux:heading>
        <flux:text>The projects you are invited to or working on.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ session('error') }}</p>
    @endif

    @forelse ($assignments as $assignment)
        <section wire:key="assignment-{{ $assignment->id }}" aria-labelledby="assignment-{{ $assignment->id }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <flux:heading id="assignment-{{ $assignment->id }}" size="lg" level="2">{{ $assignment->project->title }}</flux:heading>
                    <flux:text>{{ $assignment->role_title }} &middot; {{ $assignment->project->code }}</flux:text>
                </div>
                <x-status-badge :status="$assignment->status" />
            </div>

            @if ($assignment->status === \App\Enums\AssignmentStatus::Invited)
                <flux:text>Groibe invited you to this project. Accept to see its tasks.</flux:text>
                <div class="flex gap-3">
                    <flux:button variant="primary" wire:click="accept({{ $assignment->id }})" wire:confirm="Accept this invite?">Accept</flux:button>
                    <flux:button wire:click="decline({{ $assignment->id }})" wire:confirm="Decline this invite?">Decline</flux:button>
                </div>
            @else
                <flux:text>{{ $assignment->approved_tasks_count }} of {{ $assignment->tasks_count }} tasks approved
                    @if ($assignment->project->due_date) &middot; due {{ $assignment->project->due_date->toFormattedDateString() }} @endif
                </flux:text>
                <div>
                    <flux:button :href="route('contributor.work.project', $assignment->project)" wire:navigate>Open task board</flux:button>
                </div>
            @endif
        </section>
    @empty
        <p class="rounded-lg border border-zinc-200 p-5">You have no projects yet. When Groibe invites you to one, it will show up here.</p>
    @endforelse

    {{ $assignments->links() }}
</div>
