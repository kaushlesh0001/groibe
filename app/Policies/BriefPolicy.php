<?php

namespace App\Policies;

use App\Models\Brief;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Brief": client CR org (U while draft), delivery_manager RUA, community_manager R.
 * Client roles: owner and manager create and edit, viewer reads (SCR-002 pattern).
 */
class BriefPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isClient() || $this->adminCan($actor, 'briefs.view');
    }

    public function view(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.view') || $this->isMember($actor, $brief->organization_id);
    }

    /** A client may start a draft when they are owner or manager of their organization. */
    public function create(User $actor): bool
    {
        $organization = $actor->primaryOrganization();

        return $organization !== null && $this->isOwnerOrManager($actor, $organization);
    }

    /** Clients edit only in draft and needs_info (spec 6.1). Admins may always edit internal fields. */
    public function update(User $actor, Brief $brief): bool
    {
        if ($actor->isAdmin()) {
            return $actor->can('briefs.update');
        }

        return $brief->status->isClientEditable() && $this->isOwnerOrManager($actor, $brief->organization_id);
    }

    public function submit(User $actor, Brief $brief): bool
    {
        return $actor->isClient() && $this->isOwnerOrManager($actor, $brief->organization_id);
    }

    /** needs_info -> in_review: the client answers the admin's question, or an admin resumes it. */
    public function resume(User $actor, Brief $brief): bool
    {
        return $this->submit($actor, $brief) || $this->adminCan($actor, 'briefs.update');
    }

    public function markInReview(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.update');
    }

    public function requestInfo(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.update');
    }

    public function markScoped(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.approve');
    }

    public function decline(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.approve');
    }

    public function convert(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.approve') && $actor->can('projects.create');
    }

    public function delete(User $actor, Brief $brief): bool
    {
        return $this->adminCan($actor, 'briefs.delete');
    }

    public function restore(User $actor, Brief $brief): bool
    {
        return $this->delete($actor, $brief);
    }

    public function forceDelete(User $actor, Brief $brief): bool
    {
        return $this->delete($actor, $brief);
    }
}
