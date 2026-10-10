<?php

namespace App\Policies\Concerns;

use App\Enums\OrgRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;

/** Shared helpers so every policy resolves organization membership the same way. */
trait ChecksOrganizationAccess
{
    /** Suspended accounts are denied everything. */
    public function before(User $actor): ?bool
    {
        return $actor->status === UserStatus::Suspended ? false : null;
    }

    protected function isMember(User $actor, Organization|int $organization): bool
    {
        return $this->orgRole($actor, $organization) !== null;
    }

    /** Owner or manager: the roles that may create and change org records (SCR-002). */
    protected function isOwnerOrManager(User $actor, Organization|int $organization): bool
    {
        return in_array($this->orgRole($actor, $organization), [OrgRole::Owner, OrgRole::Manager], true);
    }

    protected function isOwner(User $actor, Organization|int $organization): bool
    {
        return $this->orgRole($actor, $organization) === OrgRole::Owner;
    }

    private function orgRole(User $actor, Organization|int $organization): ?OrgRole
    {
        $organization = $organization instanceof Organization ? $organization : Organization::query()->find($organization);

        return $organization === null ? null : $actor->organizationRole($organization);
    }

    protected function adminCan(User $actor, string $permission): bool
    {
        return $actor->isAdmin() && $actor->can($permission);
    }
}
