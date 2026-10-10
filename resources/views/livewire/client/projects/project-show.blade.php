<div class="mx-auto flex max-w-3xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-2">
        <a href="{{ route('client.projects.index') }}" class="text-sm text-brand-blue" wire:navigate>All projects</a>
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col">
                <flux:heading size="xl" level="1">{{ $project->title }}</flux:heading>
                <flux:text>{{ $project->code }}</flux:text>
            </div>
            <x-status-badge :status="$project->status" />
        </div>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-brand-green p-4">{{ session('status') }}</p>
    @endif

    <dl class="grid gap-4 sm:grid-cols-2">
        @if ($project->summary)
            <div class="sm:col-span-2">
                <dt class="text-sm font-medium text-zinc-600">Summary</dt>
                <dd class="whitespace-pre-line">{{ $project->summary }}</dd>
            </div>
        @endif
        @if ($project->scope_summary)
            <div class="sm:col-span-2">
                <dt class="text-sm font-medium text-zinc-600">Scope</dt>
                <dd class="whitespace-pre-line">{{ $project->scope_summary }}</dd>
            </div>
        @endif
        <div>
            <dt class="text-sm font-medium text-zinc-600">Type of work</dt>
            <dd>{{ $project->projectType?->name ?? 'General' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Skills</dt>
            <dd>{{ $project->skills->pluck('name')->join(', ') ?: 'None listed' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Start</dt>
            <dd>{{ $project->start_date?->format('j M Y') ?? 'To be confirmed' }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-zinc-600">Due</dt>
            <dd>{{ $project->due_date?->format('j M Y') ?? 'To be confirmed' }}</dd>
        </div>
    </dl>

    @if ($milestoneTotal > 0)
        <section aria-labelledby="progress-heading" class="flex flex-col gap-4">
            <flux:heading id="progress-heading" size="lg" level="2">Progress</flux:heading>
            <p>{{ $milestonesAccepted }} of {{ $milestoneTotal }} {{ \Illuminate\Support\Str::plural('milestone', $milestoneTotal) }} accepted</p>
            <div class="h-2 w-full overflow-hidden rounded bg-zinc-100" role="progressbar" aria-label="Milestones accepted" aria-valuemin="0" aria-valuemax="{{ $milestoneTotal }}" aria-valuenow="{{ $milestonesAccepted }}">
                <div class="h-2 bg-brand-green" style="width: {{ round($milestonesAccepted / $milestoneTotal * 100) }}%"></div>
            </div>

            @if ($milestones->isNotEmpty())
                <ul class="flex flex-col gap-3">
                    @foreach ($milestones as $milestone)
                        <li wire:key="milestone-{{ $milestone->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3">
                            <span class="flex flex-col">
                                <span class="font-medium">{{ $milestone->title }}</span>
                                <span class="text-sm text-zinc-600">
                                    @if ($milestone->client_accepted_at) Accepted {{ $milestone->client_accepted_at->format('j M Y') }}
                                    @elseif ($milestone->shared_at) Shared {{ $milestone->shared_at->format('j M Y') }} @endif
                                    @if ($milestone->due_date) &middot; due {{ $milestone->due_date->format('j M Y') }} @endif
                                </span>
                            </span>
                            <x-status-badge :status="$milestone->status" />
                        </li>
                    @endforeach
                </ul>
                <a class="text-brand-blue underline" href="{{ route('client.deliverables') }}" wire:navigate>Review deliverables</a>
            @else
                <flux:text>Finished work appears here once our team has checked it and shared it with you.</flux:text>
            @endif
        </section>
    @endif

    @unless ($teamHidden)
        <section aria-labelledby="team-heading" class="flex flex-col gap-4">
            <flux:heading id="team-heading" size="lg" level="2">Your team</flux:heading>

            @if (! $teamAvailable)
                <flux:text>The team working on your project is introduced once the Statement of Work is accepted.</flux:text>
            @elseif ($teamMembers->isEmpty())
                <flux:text>Your team is being put together.</flux:text>
            @else
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($teamMembers as $member)
                        <li class="flex flex-col rounded-lg border border-zinc-200 p-3">
                            <span class="font-medium">{{ $member['first_name'] }}</span>
                            <span class="text-sm">{{ $member['role'] }}</span>
                            @if ($member['headline']) <span class="text-sm text-zinc-600">{{ $member['headline'] }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endunless

    <section aria-labelledby="sow-heading" class="flex flex-col gap-4">
        <flux:heading id="sow-heading" size="lg" level="2">Statement of Work</flux:heading>

        @if ($sows->isEmpty())
            <flux:text>No Statement of Work has been shared yet.</flux:text>
        @endif

        @if ($openSow)
            <article class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">Version {{ $openSow->version }}</span>
                    <span class="flex gap-4 text-sm">
                        <a class="text-brand-blue" href="{{ route('client.projects.sow.print', [$project, $openSow->version]) }}" target="_blank" rel="noopener">Print view</a>
                        <a class="text-brand-blue" href="{{ route('client.projects.sow.pdf', [$project, $openSow->version]) }}">Download PDF</a>
                    </span>
                </div>

                <div class="prose max-w-none">
                    {{-- Admin-authored template output; substituted values were escaped by SowRenderer. --}}
                    {!! $openSow->body_html !!}
                </div>

                @error('accept')
                    <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p>
                @enderror

                @if ($canAccept && ! $msaAccepted)
                    <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-3">
                        Before you can accept, please <a class="text-brand-blue underline" href="{{ route('client.agreements.msa') }}">accept the Client MSA</a>.
                    </p>
                @elseif ($canAccept)
                    <form wire:submit="accept({{ $openSow->version }})" class="flex flex-col gap-3">
                        <flux:checkbox wire:model="confirmed" label="I accept this Statement of Work on behalf of {{ $project->organization->name }}." />
                        <div>
                            <flux:button type="submit" variant="primary">Accept Statement of Work</flux:button>
                        </div>
                    </form>
                @else
                    <p class="text-sm text-zinc-600">Only the organization owner can accept this Statement of Work.</p>
                @endif
            </article>
        @endif

        @foreach ($sows->where('id', '!=', $openSow?->id) as $sow)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 p-3" wire:key="sow-{{ $sow->id }}">
                <span>Version {{ $sow->version }}
                    <span class="text-sm text-zinc-600">
                        @if ($sow->accepted_at) accepted {{ $sow->accepted_at->format('j M Y') }} @else {{ $sow->status->getLabel() }} @endif
                    </span>
                </span>
                <span class="flex gap-4 text-sm">
                    <a class="text-brand-blue" href="{{ route('client.projects.sow.print', [$project, $sow->version]) }}" target="_blank" rel="noopener">Print view</a>
                    <a class="text-brand-blue" href="{{ route('client.projects.sow.pdf', [$project, $sow->version]) }}">Download PDF</a>
                </span>
            </div>
        @endforeach
    </section>
</div>
