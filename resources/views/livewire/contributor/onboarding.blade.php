<div class="mx-auto flex max-w-2xl flex-col gap-6 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Join as a contributor</flux:heading>
        <flux:text>Step {{ $step }} of {{ \App\Livewire\Contributor\Onboarding::STEPS }}</flux:text>
        <div class="h-1 w-full rounded bg-zinc-200" role="progressbar" aria-valuemin="1" aria-valuemax="{{ \App\Livewire\Contributor\Onboarding::STEPS }}" aria-valuenow="{{ $step }}">
            <div class="h-1 rounded bg-brand-green" style="width: {{ ($step / \App\Livewire\Contributor\Onboarding::STEPS) * 100 }}%"></div>
        </div>
    </div>

    @error('submit')
        <div role="alert" class="rounded-lg border border-red-400 bg-red-50 p-4">{{ $message }}</div>
    @enderror

    <form wire:submit.prevent class="flex flex-col gap-5">
        @if ($step === 1)
            <section class="flex flex-col gap-3">
                <flux:heading size="lg" level="2">Your account</flux:heading>
                <flux:text>You are signed up as {{ auth()->user()->name }} ({{ auth()->user()->email }}).</flux:text>
                @if ($emailVerified)
                    <p class="rounded-lg border border-green-400 bg-green-50 p-3">Your email address is verified.</p>
                @else
                    <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-3">
                        Please verify your email address. You cannot submit your profile for review until you do.
                        <a class="text-brand-blue underline" href="{{ route('verification.notice') }}">Resend the verification email</a>.
                    </p>
                @endif
            </section>
        @elseif ($step === 2)
            <flux:heading size="lg" level="2">About you</flux:heading>
            <flux:input wire:model="form.headline" label="Headline" required maxlength="160" description="For example: Market research analyst for consumer brands." />
            <flux:textarea wire:model="form.bio" label="Short bio" rows="5" required description="At least 30 characters. This can be shown on your public profile if you choose to make it public." />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.city" label="City" required />
                <flux:input wire:model="form.state" label="State" />
                <flux:input wire:model="form.country" label="Country" required />
                <flux:input wire:model="form.phone" label="Phone (private)" type="tel" description="Only Groibe sees this." />
                <flux:input wire:model="form.experience_years" label="Years of experience" type="number" min="0" max="60" />
                <flux:input wire:model="form.languages" label="Languages" description="Separate with commas." />
                <flux:input wire:model="form.linkedin_url" label="LinkedIn URL" type="url" placeholder="https://" />
                <flux:input wire:model="form.portfolio_url" label="Portfolio URL" type="url" placeholder="https://" />
            </div>
        @elseif ($step === 3)
            <flux:heading size="lg" level="2">Skills and availability</flux:heading>
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

            @include('livewire.contributor.partials.skill-picker')
        @elseif ($step === 4)
            <flux:heading size="lg" level="2">Member Agreement</flux:heading>
            @if ($template === null)
                <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-4">The Member Agreement is not published yet. Please check back soon.</p>
            @else
                <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-200 p-4">
                    <p class="mb-2 text-sm text-zinc-600">Version {{ $template->version }}</p>
                    <div class="prose max-w-none">{!! $template->body_html !!}</div>
                </div>
                @if ($agreementAccepted)
                    <p class="rounded-lg border border-green-400 bg-green-50 p-3">You have accepted this version of the Member Agreement.</p>
                @else
                    <flux:checkbox wire:model="agreed" label="I have read and accept the Member Agreement." />
                    @error('agreed') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            @endif
        @else
            <flux:heading size="lg" level="2">Submit for review</flux:heading>
            @if ($state === \App\Enums\OnboardingState::PendingReview)
                <p class="rounded-lg border border-blue-300 bg-blue-50 p-4">Your profile is with our team for review. We will email you when there is news.</p>
            @elseif ($state === \App\Enums\OnboardingState::Active)
                <p class="rounded-lg border border-green-400 bg-green-50 p-4">You are approved. Welcome to Groibe.</p>
            @elseif ($state === \App\Enums\OnboardingState::Rejected)
                <p class="rounded-lg border border-red-300 bg-red-50 p-4">We were not able to approve your profile at this time.</p>
            @elseif ($state === \App\Enums\OnboardingState::Suspended)
                <p class="rounded-lg border border-red-300 bg-red-50 p-4">Your account is suspended. Please contact Groibe.</p>
            @else
                <ul class="flex flex-col gap-1">
                    <li>{{ $emailVerified ? 'Done' : 'To do' }}: verify your email</li>
                    <li>{{ $state === \App\Enums\OnboardingState::ProfileComplete ? 'Done' : 'To do' }}: complete your profile and skills</li>
                    <li>{{ $agreementAccepted ? 'Done' : 'To do' }}: accept the Member Agreement</li>
                </ul>
                <flux:button variant="primary" wire:click="submitForReview" wire:loading.attr="disabled">Submit for review</flux:button>
            @endif
        @endif

        @if (! $locked)
            <div class="flex items-center justify-between gap-3">
                @if ($step > 1)
                    <flux:button wire:click="back" type="button">Back</flux:button>
                @else
                    <span></span>
                @endif

                @if ($step === 4)
                    @if (! $agreementAccepted && $template !== null)
                        <flux:button variant="primary" wire:click="acceptAgreement" type="button">Accept and continue</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="next" type="button">Continue</flux:button>
                    @endif
                @elseif ($step < \App\Livewire\Contributor\Onboarding::STEPS)
                    <flux:button variant="primary" wire:click="next" type="button">Save and continue</flux:button>
                @endif
            </div>
        @endif
    </form>
</div>
