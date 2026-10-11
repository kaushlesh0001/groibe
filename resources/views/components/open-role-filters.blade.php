@props(['action', 'filters', 'options'])

@php
    $field = 'rounded-md border border-zinc-300 px-3 py-2';
@endphp

<form method="GET" action="{{ $action }}" role="search" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="flex flex-col gap-1 sm:col-span-2 lg:col-span-3">
        <label for="q" class="text-sm font-medium">Search title and summary</label>
        <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100" class="{{ $field }}">
    </div>
    <div class="flex flex-col gap-1">
        <label for="skill" class="text-sm font-medium">Skill</label>
        <select id="skill" name="skill" class="{{ $field }}">
            <option value="">All skills</option>
            @foreach ($options['skills'] as $option)
                <option value="{{ $option->slug }}" @selected(($filters['skill'] ?? null) === $option->slug)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label for="type" class="text-sm font-medium">Type of work</label>
        <select id="type" name="type" class="{{ $field }}">
            <option value="">All types</option>
            @foreach ($options['types'] as $option)
                <option value="{{ $option->slug }}" @selected(($filters['type'] ?? null) === $option->slug)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label for="work_mode" class="text-sm font-medium">Work mode</label>
        <select id="work_mode" name="work_mode" class="{{ $field }}">
            <option value="">Any</option>
            @foreach (\App\Enums\WorkMode::cases() as $mode)
                <option value="{{ $mode->value }}" @selected(($filters['work_mode'] ?? null) === $mode->value)>{{ $mode->getLabel() }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label for="compensation" class="text-sm font-medium">Compensation</label>
        <select id="compensation" name="compensation" class="{{ $field }}">
            <option value="">Any</option>
            @foreach (\App\Enums\CompensationType::cases() as $type)
                <option value="{{ $type->value }}" @selected(($filters['compensation'] ?? null) === $type->value)>{{ $type->getLabel() }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label for="duration" class="text-sm font-medium">Duration</label>
        <select id="duration" name="duration" class="{{ $field }}">
            <option value="">Any</option>
            @foreach ($options['durations'] as $duration)
                <option value="{{ $duration }}" @selected(($filters['duration'] ?? null) === $duration)>{{ $duration }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-end">
        <button type="submit" class="rounded-md bg-brand-green px-4 py-2 font-medium text-brand-navy hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-navy">Search</button>
    </div>
</form>
