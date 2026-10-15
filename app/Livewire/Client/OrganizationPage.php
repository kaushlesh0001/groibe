<?php

namespace App\Livewire\Client;

use App\Actions\Organizations\InviteOrganizationMember;
use App\Actions\Organizations\ResendOrganizationInvite;
use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Exceptions\ActionBlocked;
use App\Livewire\Forms\OrganizationProfileForm;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Spec 12 "Organization": the profile (owner and manager edit, viewer reads), the people and their roles, and the owner's invites
 * (spec 5, SCR-002). Everything is the signed-in person's own organization; no id from the browser picks the organization.
 */
#[Title('Organization')]
class OrganizationPage extends Component
{
    use WithPagination;

    public OrganizationProfileForm $form;

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'viewer';

    public string $status = '';

    public function mount(): void
    {
        $organization = $this->organization();

        abort_if($organization === null, 404);
        Gate::authorize('view', $organization);

        $this->form->fillFrom($organization);
    }

    public function saveProfile(UpdateOrganizationProfile $update): void
    {
        $organization = $this->organization();

        abort_if($organization === null, 404);
        Gate::authorize('update', $organization);

        $update($organization, Auth::user(), $this->form->validate());

        $this->form->fillFrom($organization->refresh());
        $this->status = 'Organization details saved.';
    }

    public function invite(InviteOrganizationMember $invite): void
    {
        $organization = $this->organization();

        abort_if($organization === null, 404);
        Gate::authorize('inviteMember', $organization);

        $this->status = '';
        $this->inviteEmail = strtolower(trim($this->inviteEmail));

        $data = $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::in([OrgRole::Manager->value, OrgRole::Viewer->value])],
        ]);

        $key = 'org-invite:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('inviteEmail', 'You have sent a lot of invitations. Try again later.');

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $invite($organization, Auth::user(), $data['inviteName'], $data['inviteEmail'], OrgRole::from($data['inviteRole']));
        } catch (ActionBlocked $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->status = "Invitation sent to {$data['inviteEmail']}.";
        $this->reset('inviteName', 'inviteEmail');
        $this->resetPage();
    }

    public function resend(int $userId, ResendOrganizationInvite $resend): void
    {
        $organization = $this->organization();

        abort_if($organization === null, 404);
        Gate::authorize('inviteMember', $organization);

        $this->status = '';
        $key = 'org-invite-resend:'.$userId;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('inviteEmail', 'An invitation was sent to that person a moment ago. Try again later.');

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $member = $resend($organization, Auth::user(), $userId);
        } catch (ActionBlocked $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->status = "A new invitation was sent to {$member->email}.";
    }

    public function render()
    {
        $organization = $this->organization();

        abort_if($organization === null, 404);

        $user = Auth::user();
        $mayEdit = $user->can('update', $organization);

        $members = $organization->users()
            ->orderByPivot('created_at')
            ->orderBy('users.id')
            ->paginate(25)
            ->through(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                // Colleagues' addresses are for the people who manage the organization.
                'email' => $mayEdit ? $member->email : null,
                'role' => $member->pivot->org_role->getLabel(),
                'designation' => $member->pivot->designation,
                'waiting' => $member->password === null,
                'you' => $member->is($user),
            ]);

        return view('livewire.client.organization-page', [
            'organization' => $organization,
            'members' => $members,
            'mayEdit' => $mayEdit,
            'mayInvite' => $user->can('inviteMember', $organization),
            // The name and GSTIN are what an admin verifies, so they are the client's to change only while pending.
            'identityLocked' => $organization->status !== OrganizationStatus::Pending,
            'sizeBands' => config('groibe.organizations.size_bands'),
        ]);
    }

    private function organization(): ?Organization
    {
        return Auth::user()->primaryOrganization();
    }
}
