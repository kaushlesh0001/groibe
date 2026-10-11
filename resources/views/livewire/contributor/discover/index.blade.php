<div class="mx-auto flex max-w-5xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Discover</flux:heading>
        <flux:text>Open roles you can apply for. Groibe reviews every application.</flux:text>
    </div>

    <form wire:submit.prevent class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="search">
        <flux:input wire:model.live.debounce.400ms="q" label="Search title and summary" type="search" maxlength="100" class="sm:col-span-2 lg:col-span-3" />
        <flux:select wire:model.live="skill" label="Skill">
            <flux:select.option value="">All skills</flux:select.option>
            @foreach ($options['skills'] as $option)
                <flux:select.option value="{{ $option->slug }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="type" label="Type of work">
            <flux:select.option value="">All types</flux:select.option>
            @foreach ($options['types'] as $option)
                <flux:select.option value="{{ $option->slug }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="work_mode" label="Work mode">
            <flux:select.option value="">Any</flux:select.option>
            @foreach (\App\Enums\WorkMode::cases() as $mode)
                <flux:select.option value="{{ $mode->value }}">{{ $mode->getLabel() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="compensation" label="Compensation">
            <flux:select.option value="">Any</flux:select.option>
            @foreach (\App\Enums\CompensationType::cases() as $kind)
                <flux:select.option value="{{ $kind->value }}">{{ $kind->getLabel() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="duration" label="Duration">
            <flux:select.option value="">Any</flux:select.option>
            @foreach ($options['durations'] as $duration)
                <flux:select.option value="{{ $duration }}">{{ $duration }}</flux:select.option>
            @endforeach
        </flux:select>
    </form>

    @if ($roles->isEmpty())
        <p class="rounded-lg border border-zinc-200 p-5">No roles match right now. New roles show up here as soon as they are published.</p>
    @else
        <ul class="grid gap-4 sm:grid-cols-2">
            @foreach ($roles as $role)
                <x-open-role-card wire:key="role-{{ $role->id }}" :role="$role" :href="route('contributor.discover.show', $role->slug)" />
            @endforeach
        </ul>

        {{ $roles->links() }}
    @endif
</div>
