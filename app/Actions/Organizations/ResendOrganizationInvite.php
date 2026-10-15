<?php

namespace App\Actions\Organizations;

use App\Actions\Onboarding\SendInvite;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** The owner sends a new activation link to a colleague of this organization who has not activated yet (links expire in 7 days). */
class ResendOrganizationInvite
{
    public function __construct(private SendInvite $sendInvite) {}

    public function __invoke(Organization $organization, User $actor, int $userId): User
    {
        Gate::forUser($actor)->authorize('inviteMember', $organization);

        // Only people of this organization: another organization's member id finds nothing.
        $member = $organization->users()->whereKey($userId)->firstOrFail();

        try {
            ($this->sendInvite)($member, $actor);
        } catch (IllegalTransition) {
            throw ActionBlocked::guardFailed('That person has already activated their account.');
        }

        return $member;
    }
}
