<div class="mx-auto flex max-w-4xl flex-col gap-8 p-4 sm:p-8">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl" level="1">Organization</flux:heading>
        <flux:text>{{ $organization->name }}</flux:text>
    </div>

    <x-verification-notice :organization="$organization" />

    @if ($status !== '')
        <p role="status" class="rounded-lg border border-brand-green p-4">{{ $status }}</p>
    @endif

    <section aria-labelledby="profile-heading" class="flex flex-col gap-4">
        <flux:heading id="profile-heading" size="lg" level="2">Details</flux:heading>

        @unless ($mayEdit)
            <flux:text>You can read these details. The organization owner or a manager can change them.</flux:text>
        @endunless

        <form wire:submit="saveProfile" class="flex flex-col gap-4">
            <flux:input wire:model="form.name" label="Organization name" type="text" required :disabled="! $mayEdit || $identityLocked" :description="$identityLocked ? 'Groibe checked this when verifying. Contact Groibe to change it.' : null" />
            <flux:input wire:model="form.legal_name" label="Registered legal name" type="text" :disabled="! $mayEdit" />
            <flux:input wire:model="form.gstin" label="GSTIN" type="text" maxlength="15" :disabled="! $mayEdit || $identityLocked" />
            <flux:input wire:model="form.website" label="Website" type="url" placeholder="https://" :disabled="! $mayEdit" />
            <flux:input wire:model="form.industry" label="Industry" type="text" :disabled="! $mayEdit" />
            <flux:select wire:model="form.size_band" label="Number of people" :disabled="! $mayEdit">
                <flux:select.option value="">Choose one</flux:select.option>
                @foreach ($sizeBands as $band)
                    <flux:select.option value="{{ $band }}">{{ $band }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="form.description" label="About the organization" rows="3" :disabled="! $mayEdit" />
            <flux:input wire:model="form.city" label="City" type="text" :disabled="! $mayEdit" />
            <flux:input wire:model="form.state" label="State" type="text" :disabled="! $mayEdit" />
            <flux:input wire:model="form.country" label="Country" type="text" required :disabled="! $mayEdit" />

            @if ($mayEdit)
                <div><flux:button type="submit" variant="primary">Save details</flux:button></div>
            @endif
        </form>
    </section>

    <section aria-labelledby="people-heading" class="flex flex-col gap-4">
        <flux:heading id="people-heading" size="lg" level="2">People</flux:heading>

        <ul class="flex flex-col divide-y divide-zinc-200 rounded-lg border border-zinc-200">
            @foreach ($members as $member)
                <li class="flex flex-wrap items-center justify-between gap-3 p-4" wire:key="member-{{ $member['id'] }}">
                    <span class="flex flex-col">
                        <span class="font-medium">{{ $member['name'] }}@if ($member['you']) <span class="text-sm text-zinc-600">(you)</span>@endif</span>
                        @if ($member['email'])
                            <span class="text-sm text-zinc-600">{{ $member['email'] }}</span>
                        @endif
                        @if ($member['designation'])
                            <span class="text-sm text-zinc-600">{{ $member['designation'] }}</span>
                        @endif
                    </span>
                    <span class="flex items-center gap-3">
                        <span class="text-sm">{{ $member['role'] }}</span>
                        @if ($member['waiting'])
                            <span class="rounded border border-amber-400 bg-amber-50 px-2 py-0.5 text-xs">Invited, not activated</span>
                            @if ($mayInvite)
                                <flux:button size="sm" wire:click="resend({{ $member['id'] }})">Resend invitation</flux:button>
                            @endif
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>

        {{ $members->links() }}
    </section>

    @if ($mayInvite)
        <section aria-labelledby="invite-heading" class="flex flex-col gap-4">
            <flux:heading id="invite-heading" size="lg" level="2">Invite a colleague</flux:heading>
            <flux:text>They get an email with a link to set a password. A manager can work with briefs and deliverables; a viewer can only read. Groibe adds further owners.</flux:text>

            <form wire:submit="invite" class="flex flex-col gap-4">
                <flux:input wire:model="inviteName" label="Full name" type="text" required />
                <flux:input wire:model="inviteEmail" label="Work email" type="email" required />
                <flux:select wire:model="inviteRole" label="Role">
                    <flux:select.option value="manager">Manager</flux:select.option>
                    <flux:select.option value="viewer">Viewer</flux:select.option>
                </flux:select>
                <div><flux:button type="submit" variant="primary">Send invitation</flux:button></div>
            </form>
        </section>
    @endif
</div>
