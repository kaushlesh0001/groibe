<?php

namespace App\Policies;

use App\Models\OpenRole;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Open Role": admins with `open_roles.*` manage (delivery manager CRU, community manager CRUD, super admin all);
 * a contributor reads roles they are allowed to see (spec 2.2, SCR-018); clients and guests read nothing here.
 * Public pages use OpenRole::visibleTo(null) directly because a guest has no User to authorize.
 */
class OpenRolePolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isActiveContributor() || $this->adminCan($actor, 'open_roles.view');
    }

    public function view(User $actor, OpenRole $role): bool
    {
        if ($this->adminCan($actor, 'open_roles.view')) {
            return true;
        }

        if (! $actor->isActiveContributor()) {
            return false;
        }

        // A contributor keeps access to a role they applied to, even after it closes, so My Applications still works.
        return $role->isVisibleTo($actor) || $role->applications()->where('user_id', $actor->getKey())->exists();
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'open_roles.create');
    }

    public function update(User $actor, OpenRole $role): bool
    {
        return $this->adminCan($actor, 'open_roles.update');
    }

    /** Roles that took applications are history. */
    public function delete(User $actor, OpenRole $role): bool
    {
        return $this->adminCan($actor, 'open_roles.delete') && ! $role->applications()->exists();
    }

    /** Managing the invite list (SCR-018) is a write on the role. */
    public function manageInvites(User $actor, OpenRole $role): bool
    {
        return $this->update($actor, $role);
    }

    /** Applying needs an active contributor who can see the role and a role that is open. Duplicates are an Action rule. */
    public function apply(User $actor, OpenRole $role): bool
    {
        return $actor->isActiveContributor() && $role->isOpenForApplications() && $role->isVisibleTo($actor);
    }
}
