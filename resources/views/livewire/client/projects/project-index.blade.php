<div class="mx-auto flex max-w-4xl flex-col gap-6 p-4 sm:p-8">
    <flux:heading size="xl" level="1">Projects</flux:heading>

    @forelse ($projects as $project)
        <a href="{{ route('client.projects.show', $project) }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 hover:border-brand-blue" wire:key="project-{{ $project->id }}">
            <span class="flex flex-col">
                <span class="font-medium">{{ $project->title }}</span>
                <span class="text-sm text-zinc-600">{{ $project->code }} &middot; {{ $project->projectType?->name ?? 'General' }}</span>
            </span>
            <x-status-badge :status="$project->status" />
        </a>
    @empty
        <flux:text>No projects yet. Once a brief is scoped, your project and Statement of Work appear here.</flux:text>
    @endforelse

    {{ $projects->links() }}
</div>
