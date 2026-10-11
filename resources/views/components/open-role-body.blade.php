@props(['role'])

<div class="flex flex-col gap-6">
    <dl class="grid gap-x-8 gap-y-3 rounded-lg border border-zinc-200 p-5 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-600">Work mode</dt><dd class="font-medium">{{ $role->work_mode->getLabel() }}</dd></div>
        <div><dt class="text-sm text-zinc-600">Compensation</dt><dd class="font-medium">{{ $role->compensation_type->getLabel() }}@if ($role->compensation_note), {{ $role->compensation_note }}@endif</dd></div>
        @if ($role->est_hours_min || $role->est_hours_max)
            <div><dt class="text-sm text-zinc-600">Time needed</dt><dd class="font-medium">{{ collect([$role->est_hours_min, $role->est_hours_max])->filter()->unique()->implode(' to ') }} hours a week</dd></div>
        @endif
        @if ($role->duration_label)
            <div><dt class="text-sm text-zinc-600">Duration</dt><dd class="font-medium">{{ $role->duration_label }}</dd></div>
        @endif
        <div><dt class="text-sm text-zinc-600">Positions</dt><dd class="font-medium">{{ $role->positions_needed }}</dd></div>
        @if ($role->deadline)
            <div><dt class="text-sm text-zinc-600">Apply by</dt><dd class="font-medium">{{ $role->deadline->toFormattedDateString() }}</dd></div>
        @endif
        @if ($role->projectType)
            <div><dt class="text-sm text-zinc-600">Type of work</dt><dd class="font-medium">{{ $role->projectType->name }}</dd></div>
        @endif
    </dl>

    @if ($role->description)
        <section aria-labelledby="about-heading" class="flex flex-col gap-2">
            <h2 id="about-heading" class="text-xl font-semibold">About the role</h2>
            <div class="flex flex-col gap-3 text-zinc-800">{!! nl2br(e($role->description)) !!}</div>
        </section>
    @endif

    @if (! empty($role->tasks_list))
        <section aria-labelledby="tasks-heading" class="flex flex-col gap-2">
            <h2 id="tasks-heading" class="text-xl font-semibold">What you will do</h2>
            <ul class="list-disc space-y-1 pl-5 text-zinc-800">
                @foreach ($role->tasks_list as $task)
                    <li>{{ $task }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! empty($role->learning_outcomes))
        <section aria-labelledby="outcomes-heading" class="flex flex-col gap-2">
            <h2 id="outcomes-heading" class="text-xl font-semibold">What you will take away</h2>
            <ul class="list-disc space-y-1 pl-5 text-zinc-800">
                @foreach ($role->learning_outcomes as $outcome)
                    <li>{{ $outcome }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($role->skills->isNotEmpty())
        <section aria-labelledby="skills-heading" class="flex flex-col gap-2">
            <h2 id="skills-heading" class="text-xl font-semibold">Skills</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($role->skills as $skill)
                    <li class="rounded bg-zinc-100 px-2 py-1 text-sm">{{ $skill->name }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
