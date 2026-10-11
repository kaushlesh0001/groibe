<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Reputation</flux:heading>
        <flux:text>Facts about your work on Groibe. These are counts and averages from real projects, with no overall score.</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif

    <section aria-labelledby="facts-heading" class="flex flex-col gap-3">
        <flux:heading id="facts-heading" size="lg" level="2">Your facts</flux:heading>
        <dl class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Projects completed</dt><dd class="text-2xl font-semibold">{{ $facts->projects_completed }}</dd></div>
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Tasks approved first time</dt><dd class="text-2xl font-semibold">{{ $facts->firstPassPercent() === null ? 'Not yet' : $facts->firstPassPercent().'%' }}</dd></div>
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Tasks on time</dt><dd class="text-2xl font-semibold">{{ $facts->onTimePercent() === null ? 'Not yet' : $facts->onTimePercent().'%' }}</dd></div>
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Reviews</dt><dd class="text-2xl font-semibold">{{ $facts->reviews_count }}</dd></div>
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Average rating</dt><dd class="text-2xl font-semibold">{{ $facts->avg_rating === null ? 'Not yet' : $facts->avg_rating.' of 5' }}</dd></div>
            <div class="rounded-lg border border-zinc-200 p-4"><dt class="text-sm text-zinc-600">Verified skills</dt><dd class="text-2xl font-semibold">{{ $facts->verified_skills_count }}</dd></div>
        </dl>
        @if ($facts->recomputed_at)
            <flux:text class="text-sm">Updated {{ $facts->recomputed_at->diffForHumans() }}.</flux:text>
        @endif
    </section>

    <section aria-labelledby="received-heading" class="flex flex-col gap-3">
        <flux:heading id="received-heading" size="lg" level="2">Reviews about you</flux:heading>
        @forelse ($received as $review)
            <article wire:key="review-{{ $review->id }}" class="flex flex-col gap-1 rounded-lg border border-zinc-200 p-4">
                <p class="font-medium">{{ $review->project?->title }}</p>
                <p class="text-sm text-zinc-600">Quality {{ $review->quality }}, communication {{ $review->communication }}, reliability {{ $review->reliability }}, timeliness {{ $review->timeliness }}</p>
                @if ($review->comment)
                    <p class="whitespace-pre-line">{{ $review->comment }}</p>
                @endif
            </article>
        @empty
            <p class="rounded-lg border border-zinc-200 p-5">No published reviews yet. Client reviews appear here after Groibe has checked them.</p>
        @endforelse
    </section>

    @if ($toReview->isNotEmpty())
        <section aria-labelledby="review-client-heading" class="flex flex-col gap-4">
            <flux:heading id="review-client-heading" size="lg" level="2">Review your clients</flux:heading>
            <flux:text>Only the Groibe team sees these. They help us look after the people you work for.</flux:text>

            @foreach ($toReview as $project)
                <form wire:key="client-review-{{ $project->id }}" wire:submit="submitClientReview({{ $project->id }})" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-5">
                    <flux:heading size="md" level="3">{{ $project->title }}</flux:heading>

                    @error("project-{$project->id}")
                        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p>
                    @enderror

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($criteria as $criterion)
                            <flux:select wire:model="ratings.{{ $project->id }}.{{ $criterion }}" :label="str($criterion)->replace('_', ' ')->ucfirst()->toString()" required>
                                <flux:select.option value="">Choose 1 to 5</flux:select.option>
                                @foreach ([1, 2, 3, 4, 5] as $score)
                                    <flux:select.option value="{{ $score }}">{{ $score }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach
                    </div>

                    <flux:textarea wire:model="comments.{{ $project->id }}" label="Comment (optional)" rows="3" maxlength="2000" />

                    <div><flux:button type="submit" variant="primary">Send review</flux:button></div>
                </form>
            @endforeach
        </section>
    @endif
</div>
