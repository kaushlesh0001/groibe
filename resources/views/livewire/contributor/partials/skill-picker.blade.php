{{-- Expects $skillGroups (category name => skills) and $levels (SkillLevel cases). Bound to form.skillLevels. --}}
<fieldset class="flex flex-col gap-4">
    <legend class="text-sm font-medium">Your skills (pick at least one and say how strong you are)</legend>
    @error('form.skillLevels') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror

    @foreach ($skillGroups as $group => $skills)
        <div class="flex flex-col gap-2" wire:key="skill-group-{{ $group }}">
            <span class="text-sm font-medium text-zinc-600">{{ $group }}</span>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($skills as $skill)
                    <flux:select wire:model="form.skillLevels.{{ $skill->id }}" :label="$skill->name" wire:key="skill-{{ $skill->id }}">
                        <flux:select.option value="">Not selected</flux:select.option>
                        @foreach ($levels as $level)
                            <flux:select.option value="{{ $level->value }}">{{ $level->getLabel() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endforeach
            </div>
        </div>
    @endforeach
</fieldset>
