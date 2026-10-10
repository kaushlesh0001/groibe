<div class="mx-auto flex max-w-2xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Your profile</flux:heading>
        <flux:text>Only approved contributors who switch on the public option appear in the directory.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif

    <section aria-labelledby="photo-heading" class="flex flex-col gap-3">
        <flux:heading id="photo-heading" size="lg" level="2">Photo</flux:heading>
        @if ($photoUrl)
            <img src="{{ $photoUrl }}?v={{ $profile->updated_at->timestamp }}" alt="Your profile photo" class="size-24 rounded-full object-cover" width="96" height="96">
        @endif
        <flux:input type="file" wire:model="photo" label="Upload a photo" accept=".jpg,.jpeg,.png,.webp" description="JPG, PNG or WebP, up to 2 MB." />
        @error('photo') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
        <div class="flex gap-2">
            @if ($photo)
                <flux:button wire:click="uploadPhoto" type="button" variant="primary">Save photo</flux:button>
            @endif
            @if ($photoUrl)
                <flux:button wire:click="removePhoto" type="button">Remove photo</flux:button>
            @endif
        </div>
    </section>

    <section aria-labelledby="visibility-heading" class="flex flex-col gap-2">
        <flux:heading id="visibility-heading" size="lg" level="2">Public profile</flux:heading>
        <flux:switch wire:model.live="isPublic" label="Show my profile in the public directory" description="Your name, headline, bio, city, skills and links become visible. Your email and phone never do." />
        @if ($isPublic && ! $activeAndPublic)
            <flux:text>Your profile will go live once your application is approved.</flux:text>
        @elseif ($activeAndPublic)
            <flux:text><a class="text-brand-blue underline" href="{{ route('contributors.show', $profile->slug) }}">View your public profile</a></flux:text>
        @endif
    </section>

    <form wire:submit="save" class="flex flex-col gap-6">
        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">About you</flux:heading>
            <flux:input wire:model="form.headline" label="Headline" required maxlength="160" />
            <flux:textarea wire:model="form.bio" label="Short bio" rows="5" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.city" label="City" required />
                <flux:input wire:model="form.state" label="State" />
                <flux:input wire:model="form.country" label="Country" required />
                <flux:input wire:model="form.phone" label="Phone (private)" type="tel" />
                <flux:input wire:model="form.experience_years" label="Years of experience" type="number" min="0" max="60" />
                <flux:input wire:model="form.languages" label="Languages" description="Separate with commas." />
                <flux:input wire:model="form.linkedin_url" label="LinkedIn URL" type="url" placeholder="https://" />
                <flux:input wire:model="form.portfolio_url" label="Portfolio URL" type="url" placeholder="https://" />
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <flux:heading size="lg" level="2">Availability</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.availability" label="Availability" required>
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach (\App\Enums\Availability::cases() as $option)
                        <flux:select.option value="{{ $option->value }}">{{ $option->getLabel() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="form.hours_per_week" label="Hours per week" type="number" min="1" max="80" />
            </div>
            <fieldset class="flex flex-col gap-2">
                <legend class="text-sm font-medium">How you like to work</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ($workModes as $value => $label)
                        <flux:checkbox wire:model="form.work_modes" value="{{ $value }}" label="{{ $label }}" wire:key="mode-{{ $value }}" />
                    @endforeach
                </div>
            </fieldset>
        </section>

        @include('livewire.contributor.partials.skill-picker')

        <div>
            <flux:button type="submit" variant="primary">Save profile</flux:button>
        </div>
    </form>
</div>
