<x-filament-panels::page>
    @forelse ($milestones as $milestone)
        @php
            $total = $milestone->tasks->count();
            $approved = $milestone->tasks->where('status', \App\Enums\TaskStatus::Approved)->count();
        @endphp

        <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900" aria-label="{{ $milestone->title }}">
            <header class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">{{ $loop->iteration }}. {{ $milestone->title }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ $milestone->status->getLabel() }}
                        @if ($milestone->due_date) &middot; due {{ $milestone->due_date->toFormattedDateString() }} @endif
                        &middot; {{ $approved }} of {{ $total }} tasks approved
                    </p>
                </div>

                @if ($canReorder)
                    <div class="flex gap-2">
                        <x-filament::icon-button icon="heroicon-m-arrow-up" label="Move {{ $milestone->title }} up" wire:click="moveMilestone({{ $milestone->id }}, 'up')" :disabled="$loop->first" />
                        <x-filament::icon-button icon="heroicon-m-arrow-down" label="Move {{ $milestone->title }} down" wire:click="moveMilestone({{ $milestone->id }}, 'down')" :disabled="$loop->last" />
                    </div>
                @endif
            </header>

            @if ($total > 0)
                <div class="mt-3 h-2 w-full overflow-hidden rounded bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $approved }}">
                    <div class="h-2 bg-primary-600" style="width: {{ round($approved / $total * 100) }}%"></div>
                </div>

                <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($milestone->tasks as $task)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="task-{{ $task->id }}">
                            <div>
                                <p class="text-sm font-medium">{{ $task->title }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $task->status->getLabel() }}
                                    @if ($task->estimate_hours) &middot; {{ rtrim(rtrim((string) $task->estimate_hours, '0'), '.') }} h @endif
                                    @if ($task->due_date) &middot; due {{ $task->due_date->toFormattedDateString() }} @endif
                                </p>
                            </div>

                            @if ($canAssign && $task->status === \App\Enums\TaskStatus::Todo)
                                <label class="text-sm">
                                    <span class="sr-only">Assign {{ $task->title }}</span>
                                    <select class="rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800" wire:change="assignTask({{ $task->id }}, $event.target.value)">
                                        <option value="">Unassigned</option>
                                        @foreach ($assignmentOptions as $id => $label)
                                            <option value="{{ $id }}" @selected($task->assignment_id === $id)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @else
                                <span class="text-sm text-gray-600 dark:text-gray-300">{{ $task->assignment?->user?->name ?? 'Unassigned' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-gray-500">No tasks yet.</p>
            @endif
        </section>
    @empty
        <p class="text-sm text-gray-500">No milestones yet. Add the first one to start planning.</p>
    @endforelse
</x-filament-panels::page>
