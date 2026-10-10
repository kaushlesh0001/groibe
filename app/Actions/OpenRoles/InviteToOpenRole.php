<?php

namespace App\Actions\OpenRoles;

use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Exceptions\ActionBlocked;
use App\Models\OpenRole;
use App\Models\User;
use App\Notifications\OpenRoleInvited;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Adds an active contributor to the invite list of an `invite_only` role (SCR-018). Idempotent. */
class InviteToOpenRole
{
    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(OpenRole $role, User $contributor, User $actor): bool
    {
        Gate::forUser($actor)->authorize('manageInvites', $role);

        if ($role->visibility !== OpenRoleVisibility::InviteOnly) {
            throw ActionBlocked::guardFailed('Only an invite-only role has an invite list.');
        }

        if (! in_array($role->status, [OpenRoleStatus::Draft, OpenRoleStatus::Published], true)) {
            throw ActionBlocked::guardFailed('Invites can only be added to a draft or published role.');
        }

        $contributor->loadMissing('profile');

        if (! $contributor->isActiveContributor()) {
            throw ActionBlocked::guardFailed('Only active contributors can be invited.');
        }

        $added = DB::transaction(function () use ($role, $contributor, $actor): bool {
            if ($role->invitedUsers()->whereKey($contributor->getKey())->exists()) {
                return false;
            }

            $role->invitedUsers()->attach($contributor->getKey(), ['invited_by' => $actor->getKey()]);

            activity('open_role')
                ->causedBy($actor)
                ->performedOn($role)
                ->event('invited')
                ->withProperties(['user_id' => $contributor->getKey()])
                ->log('Contributor added to the invite list');

            return true;
        });

        // The contributor is only told once the role is live, because they cannot see a draft.
        if ($added && $role->status === OpenRoleStatus::Published) {
            $this->dispatcher->send($contributor, new OpenRoleInvited($role));
        }

        return $added;
    }
}
