<?php

namespace App\Actions\OpenRoles;

use App\Models\OpenRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Takes a contributor off an invite list (SCR-018). An application they already made is left alone. */
class RemoveOpenRoleInvite
{
    public function __invoke(OpenRole $role, User $contributor, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageInvites', $role);

        if ($role->invitedUsers()->detach($contributor->getKey()) > 0) {
            activity('open_role')
                ->causedBy($actor)
                ->performedOn($role)
                ->event('invite_removed')
                ->withProperties(['user_id' => $contributor->getKey()])
                ->log('Contributor removed from the invite list');
        }
    }
}
