<div class="mx-auto flex max-w-3xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-2">
        <a href="{{ route('client.briefs.index') }}" class="text-sm text-brand-blue" wire:navigate>All briefs</a>
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl" level="1">{{ $brief->title }}</flux:heading>
            <x-status-badge :status="$brief->status" />
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-brand-green p-4">{{ session('status') }}</p>
    @endif

    @if ($question)
        <section role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-4">
            <flux:heading size="lg" level="2">We have a question</flux:heading>
            <p class="mt-2 whitespace-pre-line">{{ $question }}</p>
        </section>
    @endif

    @if ($canEdit)
        <div>
            <flux:button :href="route('client.briefs.edit', $brief)" variant="primary" wire:navigate>
                {{ $brief->status->value === 'needs_info' ? 'Answer and update brief' : 'Continue editing' }}
            </flux:button>
        </div>
    @endif

    @if ($brief->status->value === 'converted')
        <p class="rounded-lg border border-zinc-200 p-4">Our team is preparing your Statement of Work. You will be notified when it is ready to review.</p>
    @elseif ($brief->status->value === 'scoped')
        <p class="rounded-lg border border-zinc-200 p-4">Your brief has been scoped. A proposal will follow shortly.</p>
    @endif

    <dl class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <dt class="text-sm font-medium text-zinc-600">Problem</dt>
            <dd class="whitespace-pre-line">{{ $brief->problem }}</dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="text-sm font-medium text-zinc-600">Desired outcome</dt>
            <dd class="whitespace-pre-line">{{ $brief->desired_outcome }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Type of work</dt>
            <dd>{{ $brief->projectType?->name ?? 'Not chosen' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Skills</dt>
            <dd>{{ $brief->skills->pluck('name')->join(', ') ?: 'None selected' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Budget</dt>
            <dd>{{ $budgetLabel ?? 'Not set' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Timeline</dt>
            <dd>{{ $timelineLabel ?? 'Not set' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Target start</dt>
            <dd>{{ $brief->target_start?->format('j M Y') ?? 'Not set' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Deadline</dt>
            <dd>{{ $brief->deadline?->format('j M Y') ?? 'Not set' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Confidentiality</dt>
            <dd>{{ $brief->confidentiality->getLabel() }}</dd>
        </div>
    </dl>

    <section aria-labelledby="files-heading" class="flex flex-col gap-2">
        <flux:heading id="files-heading" size="lg" level="2">Files</flux:heading>
        <ul class="flex flex-col gap-1">
            @forelse ($files as $file)
                <li><a href="{{ $file['url'] }}" class="text-brand-blue">{{ $file['name'] }}</a> <span class="text-sm text-zinc-600">({{ $file['size'] }})</span></li>
            @empty
                <li class="text-zinc-600">No files attached.</li>
            @endforelse
        </ul>
    </section>
</div>
