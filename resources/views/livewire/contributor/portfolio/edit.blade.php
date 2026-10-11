<div class="mx-auto flex max-w-2xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-2">
        <a class="w-fit text-brand-blue underline" href="{{ route('contributor.portfolio') }}" wire:navigate>Back to portfolio</a>
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ $proof->title }}</flux:heading>
            <x-status-badge :status="$proof->status" />
        </div>
        <flux:text>{{ $proof->role_title }}</flux:text>
    </div>

    @if (session('status'))
        <p role="status" class="rounded-lg border border-green-400 bg-green-50 p-3">{{ session('status') }}</p>
    @endif
    @error('publication')
        <p role="alert" class="rounded-lg border border-red-400 bg-red-50 p-3">{{ $message }}</p>
    @enderror

    @if ($consent === \App\Enums\ShowcaseConsent::None)
        <p class="rounded-lg border border-amber-400 bg-amber-50 p-3">The client has not yet allowed this project to be shown. You can finish your wording now, and it can be published once they agree.</p>
    @elseif ($consent === \App\Enums\ShowcaseConsent::Anonymous)
        <p class="rounded-lg border border-zinc-200 p-3">The client asked to stay anonymous. Do not name them in your text.</p>
    @endif

    @if ($proof->status === \App\Enums\ProofStatus::PendingApproval)
        <p class="rounded-lg border border-zinc-200 p-3">Groibe is reviewing this. You will be told when it is published or if edits are needed.</p>
    @endif

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input wire:model="title" label="Title" required maxlength="150" :disabled="! $editable" />
        <flux:input wire:model="summary" label="Summary" maxlength="300" description="One or two sentences that make sense without the project context." :disabled="! $editable" />
        <flux:textarea wire:model="contributionText" label="What you did" rows="7" description="Your own contribution, in your words." :disabled="! $editable" />

        <fieldset class="flex flex-col gap-3" @disabled(! $editable)>
            <legend class="font-medium">Outcomes</legend>
            @foreach ($metrics as $index => $metric)
                <div wire:key="metric-{{ $index }}" class="flex items-end gap-3">
                    <flux:input wire:model="metrics.{{ $index }}.label" label="Measure" placeholder="Reports delivered" class="flex-1" :disabled="! $editable" />
                    <flux:input wire:model="metrics.{{ $index }}.value" label="Result" placeholder="12" class="w-32" :disabled="! $editable" />
                    @if ($editable)
                        <flux:button type="button" wire:click="removeMetric({{ $index }})" aria-label="Remove outcome {{ $index + 1 }}">Remove</flux:button>
                    @endif
                </div>
            @endforeach
            @if ($editable && count($metrics) < $maxMetrics)
                <div><flux:button type="button" wire:click="addMetric">Add an outcome</flux:button></div>
            @endif
        </fieldset>

        @if ($editable)
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary">Save draft</flux:button>
                <flux:button type="button" wire:click="requestPublication" wire:confirm="Send this to Groibe for approval? You cannot edit it while it is being reviewed.">Ask for publication</flux:button>
            </div>
        @endif
    </form>
</div>
