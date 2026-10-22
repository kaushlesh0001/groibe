<div class="mx-auto flex max-w-3xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-2">
        <a class="text-sm text-brand-blue underline" href="{{ route('contributor.work.project', $project) }}" wire:navigate>{{ $project->title }}</a>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="xl" level="1">{{ $task->title }}</flux:heading>
            <x-status-badge :status="$task->status" />
        </div>
        <flux:text>
            {{ $task->milestone->title }}
            @if ($task->due_date) &middot; due {{ $task->due_date->toFormattedDateString() }} @endif
            @if ($task->estimate_hours) &middot; {{ rtrim(rtrim((string) $task->estimate_hours, '0'), '.') }} h estimate @endif
        </flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @error('action') <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p> @enderror

    @if ($task->description)
        <section aria-labelledby="brief-heading" class="flex flex-col gap-2">
            <flux:heading id="brief-heading" size="lg" level="2">What is needed</flux:heading>
            <p class="whitespace-pre-line">{{ $task->description }}</p>
        </section>
    @endif

    <section aria-labelledby="work-heading" class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-5">
        <flux:heading id="work-heading" size="lg" level="2">Your work</flux:heading>

        @if ($task->status === \App\Enums\TaskStatus::Todo)
            <flux:text>Start the task when you begin working on it.</flux:text>
            @if ($canWork) <div><flux:button variant="primary" wire:click="start">Start task</flux:button></div> @endif
        @elseif ($task->status === \App\Enums\TaskStatus::ChangesRequested)
            <flux:text>Groibe asked for changes. Read the note under your latest submission, then start revising.</flux:text>
            @if ($canWork) <div><flux:button variant="primary" wire:click="resume">Start revising</flux:button></div> @endif
        @elseif ($task->status === \App\Enums\TaskStatus::InProgress)
            @if ($canWork)
                <form wire:submit="submit" class="flex flex-col gap-4">
                    <flux:textarea wire:model="note" label="Note for the reviewer" rows="4" description="What you did and anything the reviewer should know." />
                    <flux:input type="file" wire:model="uploads" multiple label="Files" description="Up to {{ $maxFiles }} files, {{ $maxMb }} MB each. Allowed: {{ $extensions }}." />
                    @error('uploads') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit,uploads">Submit work</flux:button></div>
                </form>
            @endif
        @elseif ($task->status === \App\Enums\TaskStatus::Submitted)
            <flux:text>Submitted. Groibe is reviewing it.</flux:text>
        @else
            <flux:text>Approved. Nothing more to do on this task.</flux:text>
        @endif
    </section>

    <section aria-labelledby="history-heading" class="flex flex-col gap-3">
        <flux:heading id="history-heading" size="lg" level="2">Submissions</flux:heading>
        @forelse ($submissions as $submission)
            <article wire:key="submission-{{ $submission->id }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm" level="3">Version {{ $submission->version }} &middot; {{ $submission->created_at->toDayDateTimeString() }}</flux:heading>
                    <x-status-badge :status="$submission->status" />
                </div>
                @if ($submission->note) <p class="whitespace-pre-line">{{ $submission->note }}</p> @endif
                @if ($files[$submission->id] !== [])
                    <ul class="list-disc pl-5 text-sm">
                        @foreach ($files[$submission->id] as $file)
                            <li><a class="text-brand-blue underline" href="{{ $file['url'] }}">{{ $file['name'] }}</a> <span class="text-zinc-600">({{ $file['size'] }})</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($submission->qa_note)
                    <p class="rounded-lg border border-amber-400 bg-amber-50 p-3"><strong>Reviewer note:</strong> {{ $submission->qa_note }}</p>
                @endif
            </article>
        @empty
            <p class="text-sm text-zinc-600">Nothing submitted yet.</p>
        @endforelse
    </section>

    <section aria-labelledby="comments-heading" class="flex flex-col gap-3">
        <flux:heading id="comments-heading" size="lg" level="2">Comments</flux:heading>
        @forelse ($comments as $item)
            <div wire:key="comment-{{ $item->id }}" class="rounded-lg border border-zinc-200 p-3">
                <p class="text-sm text-zinc-600">{{ $labels->forTaskComment($item->user) }} &middot; {{ $item->created_at->toDayDateTimeString() }}</p>
                <p class="whitespace-pre-line">{{ $item->body }}</p>
            </div>
        @empty
            <p class="text-sm text-zinc-600">No comments yet.</p>
        @endforelse

        @if ($canComment)
            <form wire:submit="addComment" class="flex flex-col gap-3">
                <flux:textarea wire:model="comment" label="Add a comment" rows="3" />
                <div><flux:button type="submit">Post comment</flux:button></div>
            </form>
        @endif
    </section>
</div>
