<div class="mx-auto flex max-w-2xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">{{ $brief ? 'Edit brief' : 'New brief' }}</flux:heading>
        <flux:text>Step {{ $step }} of {{ \App\Livewire\Forms\BriefDetailsForm::STEPS }}</flux:text>
        <div class="h-1 w-full rounded bg-zinc-200" role="progressbar" aria-valuemin="1" aria-valuemax="{{ \App\Livewire\Forms\BriefDetailsForm::STEPS }}" aria-valuenow="{{ $step }}">
            <div class="h-1 rounded bg-brand-green-alt" style="width: {{ ($step / \App\Livewire\Forms\BriefDetailsForm::STEPS) * 100 }}%"></div>
        </div>
    </div>

    @if ($question)
        <section role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-4">
            <flux:heading size="lg" level="2">Our question</flux:heading>
            <p class="mt-2 whitespace-pre-line">{{ $question }}</p>
        </section>
    @endif

    @error('submit')
        <div role="alert" class="rounded-lg border border-red-400 bg-red-50 p-4">
            <p>{{ $message }}</p>
            @if (str_contains($message, 'Client MSA'))
                <p class="mt-2"><a class="text-brand-blue underline" href="{{ $msaUrl }}">Open the Client MSA</a></p>
            @endif
        </div>
    @enderror

    <form wire:submit.prevent class="flex flex-col gap-5">
        @if ($step === 1)
            <flux:input wire:model="form.title" label="Brief title" required maxlength="255" description="A short name so you can find it later." />
            <flux:textarea wire:model="form.problem" label="What problem are you trying to solve?" rows="6" required />
            <flux:textarea wire:model="form.desired_outcome" label="What does a good result look like?" rows="5" required />
        @elseif ($step === 2)
            <flux:select wire:model="form.project_type_id" label="Type of work" required>
                <flux:select.option value="">Choose one</flux:select.option>
                @foreach ($projectTypes as $type)
                    <flux:select.option value="{{ $type->id }}">{{ $type->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <fieldset class="flex flex-col gap-3">
                <legend class="text-sm font-medium">Skills you expect this to need (optional)</legend>
                @foreach ($skillGroups as $group => $skills)
                    <div class="flex flex-col gap-1" wire:key="skills-{{ $group }}">
                        <span class="text-sm font-medium text-zinc-600">{{ $group }}</span>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($skills as $skill)
                                <flux:checkbox wire:model="form.skill_ids" value="{{ $skill->id }}" label="{{ $skill->name }}" wire:key="skill-{{ $skill->id }}" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </fieldset>
        @elseif ($step === 3)
            <flux:select wire:model="form.budget_band" label="Budget range" required>
                <flux:select.option value="">Choose one</flux:select.option>
                @foreach ($budgetBands as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="form.timeline_band" label="Timeline" required>
                <flux:select.option value="">Choose one</flux:select.option>
                @foreach ($timelineBands as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.target_start" type="date" label="Target start (optional)" />
                <flux:input wire:model="form.deadline" type="date" label="Deadline (optional)" />
            </div>
        @elseif ($step === 4)
            <flux:select wire:model="form.confidentiality" label="Confidentiality" required>
                <flux:select.option value="standard">Standard</flux:select.option>
                <flux:select.option value="confidential">Confidential (limit what we share about this work)</flux:select.option>
            </flux:select>

            <div class="flex flex-col gap-2">
                <label for="brief-uploads" class="text-sm font-medium">Supporting files (optional)</label>
                <input id="brief-uploads" type="file" wire:model="uploads" multiple class="text-sm">
                <p class="text-sm text-zinc-600">PDF, Office documents, CSV, text or images. Up to 20 MB each.</p>
                <div wire:loading wire:target="uploads" class="text-sm text-zinc-600">Uploading...</div>
                @error('uploads') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                @error('uploads.*') <p class="text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($files->isNotEmpty())
                <ul class="flex flex-col gap-1">
                    @foreach ($files as $file)
                        <li class="flex items-center justify-between gap-4" wire:key="file-{{ $file['id'] }}">
                            <span><a href="{{ $file['url'] }}" class="text-brand-blue">{{ $file['name'] }}</a> <span class="text-sm text-zinc-600">({{ $file['size'] }})</span></span>
                            <button type="button" wire:click="removeFile({{ $file['id'] }})" wire:confirm="Remove this file?" class="text-sm text-red-600 underline">Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <dl class="grid gap-3 rounded-lg border border-zinc-200 p-4">
                <div><dt class="text-sm text-zinc-600">Title</dt><dd>{{ $form->title }}</dd></div>
                <div><dt class="text-sm text-zinc-600">Problem</dt><dd class="whitespace-pre-line">{{ $form->problem }}</dd></div>
                <div><dt class="text-sm text-zinc-600">Desired outcome</dt><dd class="whitespace-pre-line">{{ $form->desired_outcome }}</dd></div>
                <div><dt class="text-sm text-zinc-600">Budget</dt><dd>{{ $budgetBands[$form->budget_band] ?? 'Not set' }}</dd></div>
                <div><dt class="text-sm text-zinc-600">Timeline</dt><dd>{{ $timelineBands[$form->timeline_band] ?? 'Not set' }}</dd></div>
                <div><dt class="text-sm text-zinc-600">Files</dt><dd>{{ $files->count() }} attached</dd></div>
            </dl>
            <flux:text>{{ $isResponse ? 'Send your answers back to the Groibe team.' : 'When you submit, the Groibe team will review your brief.' }}</flux:text>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-3">
                @if ($step > 1)
                    <flux:button type="button" wire:click="back">Back</flux:button>
                @endif
                @if ($brief || $step > 1)
                    <flux:button type="button" variant="ghost" wire:click="saveAndExit">Save and exit</flux:button>
                @endif
            </div>

            @if ($step < \App\Livewire\Forms\BriefDetailsForm::STEPS)
                <flux:button type="button" variant="primary" wire:click="next">Next</flux:button>
            @else
                <flux:button type="button" variant="primary" wire:click="submit">{{ $isResponse ? 'Send answers' : 'Submit brief' }}</flux:button>
            @endif
        </div>
    </form>
</div>
