<?php

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Staffing rules from spec 5 and 6.6. Admins with project write access invite and remove; the invited
 * contributor accepts or declines their own seat. Clients never see assignments (rates are internal).
 */
class AssignmentPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $this->adminCan($actor, 'projects.view');
    }

    public function view(User $actor, Assignment $assignment): bool
    {
        return $this->adminCan($actor, 'projects.view')
            || ($actor->isContributor() && $assignment->user_id === $actor->getKey());
    }

    public function invite(User $actor, Project $project): bool
    {
        return $this->adminCan($actor, 'projects.update');
    }

    /** Accept or decline: only the invited contributor, only while the invite is open. */
    public function respond(User $actor, Assignment $assignment): bool
    {
        return $actor->isActiveContributor()
            && $assignment->user_id === $actor->getKey()
            && $assignment->status === AssignmentStatus::Invited;
    }

    /** The agreed amount payouts are planned against (SCR-024): delivery managers and super admins, who own the staffing. */
    public function setAmount(User $actor, Assignment $assignment): bool
    {
        return $this->adminCan($actor, 'projects.update');
    }

    public function remove(User $actor, Assignment $assignment): bool
    {
        return $this->adminCan($actor, 'projects.update');
    }
}
