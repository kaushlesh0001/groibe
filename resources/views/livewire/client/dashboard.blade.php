<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Welcome, {{ auth()->user()->name }}</flux:heading>
        <flux:text>{{ $organization?->name ?? 'Your account is not linked to an organization yet. Please contact Groibe.' }}</flux:text>
    </div>

    @if ($msaPending)
        <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-4">
            @if ($isOwner)
                Before you can submit briefs or accept a Statement of Work, please <a class="text-brand-blue underline" href="{{ route('client.agreements.msa') }}">review and accept the Client MSA</a>.
            @else
                The organization owner needs to accept the Client MSA before briefs can be submitted or a Statement of Work accepted.
            @endif
        </p>
    @endif

    <section aria-labelledby="action-heading" class="flex flex-col gap-3">
        <flux:heading id="action-heading" size="lg" level="2">Needs your attention</flux:heading>

        @forelse ($awaitingSow as $project)
            <a href="{{ route('client.projects.show', $project) }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 hover:border-brand-blue">
                <span><span class="font-medium">{{ $project->title }}</span> <span class="text-sm text-zinc-600">({{ $project->code }})</span></span>
                <span class="text-sm text-brand-blue">Review Statement of Work</span>
            </a>
        @empty
        @endforelse

        @foreach ($needsInfo as $brief)
            <a href="{{ route('client.briefs.show', $brief) }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 hover:border-brand-blue">
                <span class="font-medium">{{ $brief->title }}</span>
                <span class="text-sm text-brand-blue">We have a question</span>
            </a>
        @endforeach

        @if ($awaitingSow->isEmpty() && $needsInfo->isEmpty())
            <flux:text>Nothing needs your attention right now.</flux:text>
        @endif
    </section>

    <section aria-labelledby="projects-heading" class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading id="projects-heading" size="lg" level="2">Projects in progress</flux:heading>
            <a href="{{ route('client.projects.index') }}" class="text-sm text-brand-blue">All projects</a>
        </div>

        @forelse ($activeProjects as $project)
            <a href="{{ route('client.projects.show', $project) }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 hover:border-brand-blue">
                <span><span class="font-medium">{{ $project->title }}</span> <span class="text-sm text-zinc-600">({{ $project->code }})</span></span>
                <x-status-badge :status="$project->status" />
            </a>
        @empty
            <flux:text>No projects in progress yet.</flux:text>
        @endforelse
    </section>

    <section aria-labelledby="briefs-heading" class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading id="briefs-heading" size="lg" level="2">Briefs</flux:heading>
            <a href="{{ route('client.briefs.index') }}" class="text-sm text-brand-blue">All briefs</a>
        </div>
        @if ($briefCounts === [])
            <flux:text>You have not submitted a brief yet.</flux:text>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach ($briefCounts as $status => $total)
                    <li class="rounded-full border border-zinc-200 px-3 py-1 text-sm">{{ Str::headline($status) }}: {{ $total }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
