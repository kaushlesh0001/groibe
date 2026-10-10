<?php

namespace App\Policies;

use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->status === UserStatus::Suspended ? false : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && $actor->can('organizations.view');
    }

    /** Admins with permission, or members of this organization only. */
    public function view(User $actor, Organization $organization): bool
    {
        return ($actor->isAdmin() && $actor->can('organizations.view'))
            || $this->orgRole($actor, $organization) !== null;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin() && $actor->can('organizations.create');
    }

    /** Spec 5: client "RU own org". Viewers are read-only; owners and managers may edit. */
    public function update(User $actor, Organization $organization): bool
    {
        if ($actor->isAdmin()) {
            return $actor->can('organizations.update');
        }

        return in_array($this->orgRole($actor, $organization), [OrgRole::Owner, OrgRole::Manager], true);
    }

    /** Spec 12: only the owner invites team members. */
    public function inviteMember(User $actor, Organization $organization): bool
    {
        if ($actor->isAdmin()) {
            return $actor->can('organizations.update');
        }

        return $this->orgRole($actor, $organization) === OrgRole::Owner;
    }

    public function delete(User $actor, Organization $organization): bool
    {
        return $actor->isAdmin() && $actor->can('organizations.delete');
    }

    public function restore(User $actor, Organization $organization): bool
    {
        return $this->delete($actor, $organization);
    }

    public function forceDelete(User $actor, Organization $organization): bool
    {
        return $this->delete($actor, $organization);
    }

    private function orgRole(User $actor, Organization $organization): ?OrgRole
    {
        if ($actor->role !== UserRole::Client) {
            return null;
        }

        $role = $organization->users()->whereKey($actor->getKey())->first()?->pivot?->org_role;

        return $role instanceof OrgRole ? $role : null;
    }
}
