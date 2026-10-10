<div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <a class="text-sm text-brand-blue underline" href="{{ route('contributor.work') }}" wire:navigate>All my work</a>
        <flux:heading size="xl" level="1">{{ $project->title }}</flux:heading>
        <flux:text>Your tasks on this project.</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($columns as $heading => $items)
            <section aria-labelledby="col-{{ \Illuminate\Support\Str::slug($heading) }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3">
                <flux:heading id="col-{{ \Illuminate\Support\Str::slug($heading) }}" size="sm" level="2">{{ $heading }} ({{ $items->count() }})</flux:heading>

                @forelse ($items as $task)
                    <a wire:key="task-{{ $task->id }}" href="{{ route('contributor.work.task', $task) }}" wire:navigate class="flex flex-col gap-1 rounded-lg border border-zinc-200 bg-white p-3 hover:border-brand-blue">
                        <span class="font-medium">{{ $task->title }}</span>
                        <span class="text-sm text-zinc-600">{{ $task->milestone->title }}</span>
                        <span class="flex flex-wrap items-center gap-2 text-sm text-zinc-600">
                            @if ($task->status === \App\Enums\TaskStatus::ChangesRequested)
                                <x-status-badge :status="$task->status" />
                            @endif
                            @if ($task->due_date) <span>Due {{ $task->due_date->toFormattedDateString() }}</span> @endif
                            @if ($task->estimate_hours) <span>{{ rtrim(rtrim((string) $task->estimate_hours, '0'), '.') }} h</span> @endif
                        </span>
                    </a>
                @empty
                    <p class="text-sm text-zinc-500">Nothing here.</p>
                @endforelse
            </section>
        @endforeach
    </div>

    {{ $tasks->links() }}
</div>
