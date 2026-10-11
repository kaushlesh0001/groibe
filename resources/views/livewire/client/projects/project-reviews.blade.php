<div class="mx-auto flex max-w-3xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-2">
        <a class="w-fit text-brand-blue underline" href="{{ route('client.projects.show', $project) }}" wire:navigate>Back to the project</a>
        <flux:heading size="xl" level="1">Reviews and showcase</flux:heading>
        <flux:text>{{ $project->title }}</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif

    <section aria-labelledby="consent-heading" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
        <flux:heading id="consent-heading" size="lg" level="2">Showing this project</flux:heading>
        <flux:text>Groibe may show the work on this project as an example of what we deliver. You decide how. You can change this at any time, and a change takes effect straight away.</flux:text>

        @error('consent')
            <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p>
        @enderror

        <form wire:submit="saveConsent" class="flex flex-col gap-4">
            <flux:radio.group wire:model="consent" label="How may we show it?">
                <flux:radio value="none" label="Do not show it" description="Nothing about this project appears publicly." :disabled="! $canManage" />
                <flux:radio value="anonymous" label="Show it without naming us" description="Shown as a confidential client in your industry." :disabled="! $canManage" />
                <flux:radio value="named" label="Show it with our name" description="Your organization name appears with the work." :disabled="! $canManage" />
            </flux:radio.group>

            @if ($canManage)
                <div><flux:button type="submit" variant="primary">Save my choice</flux:button></div>
            @else
                <flux:text>Only an owner or manager of your organization can change this.</flux:text>
            @endif
        </form>
    </section>

    <section aria-labelledby="team-reviews-heading" class="flex flex-col gap-4">
        <flux:heading id="team-reviews-heading" size="lg" level="2">Review the team</flux:heading>
        <flux:text>Reviews are checked by Groibe before they are published. They cannot be changed once sent.</flux:text>

        @forelse ($team as $member)
            <div wire:key="member-{{ $member['assignment_id'] }}" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
                <flux:heading size="md" level="3">{{ $member['label'] }}</flux:heading>

                @if ($member['review'])
                    <flux:text>Review sent. {{ $member['review']->status === \App\Enums\ReviewStatus::Published ? 'It is published.' : 'It is being checked by Groibe.' }}</flux:text>
                @elseif ($canManage)
                    <form wire:submit="submitReview({{ $member['assignment_id'] }})" class="flex flex-col gap-4">
                        @error('assignment-'.$member['assignment_id'])
                            <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($criteria as $criterion)
                                <flux:select wire:model="ratings.{{ $member['assignment_id'] }}.{{ $criterion }}" :label="str($criterion)->ucfirst()->toString()" required>
                                    <flux:select.option value="">Choose 1 to 5</flux:select.option>
                                    @foreach ([1, 2, 3, 4, 5] as $score)
                                        <flux:select.option value="{{ $score }}">{{ $score }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endforeach
                        </div>

                        <flux:textarea wire:model="comments.{{ $member['assignment_id'] }}" label="Comment (optional)" rows="3" maxlength="2000" />

                        <div><flux:button type="submit" variant="primary">Send review</flux:button></div>
                    </form>
                @else
                    <flux:text>Only an owner or manager of your organization can write reviews.</flux:text>
                @endif
            </div>
        @empty
            <p class="rounded-lg border border-zinc-200 p-5">There is no one to review on this project.</p>
        @endforelse
    </section>
</div>
