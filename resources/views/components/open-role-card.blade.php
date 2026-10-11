@props(['role', 'href'])

<li class="rounded-lg border border-zinc-200 p-5 hover:border-brand-blue">
    <a href="{{ $href }}" class="flex flex-col gap-2">
        <span class="text-lg font-semibold">{{ $role->title }}</span>
        @if ($role->summary)
            <span class="text-zinc-700">{{ \Illuminate\Support\Str::limit($role->summary, 140) }}</span>
        @endif
        <span class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-600">
            <span>{{ $role->work_mode->getLabel() }}</span>
            <span>{{ $role->compensation_type->getLabel() }}</span>
            @if ($role->duration_label)
                <span>{{ $role->duration_label }}</span>
            @endif
            @if ($role->deadline)
                <span>Apply by {{ $role->deadline->toFormattedDateString() }}</span>
            @endif
        </span>
        <span class="flex flex-wrap gap-1">
            @foreach ($role->skills->take(4) as $skill)
                <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs">{{ $skill->name }}</span>
            @endforeach
        </span>
    </a>
</li>
