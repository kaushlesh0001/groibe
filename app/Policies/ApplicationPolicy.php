<?php

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Application": the contributor creates and reads their own and withdraws; delivery and community managers
 * read, update (shortlist, reject) and approve (offer); super admin all. Clients never read applications here;
 * the optional shortlist view is a separate, limited projection (spec 12).
 */
class ApplicationPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isActiveContributor() || $this->adminCan($actor, 'applications.view');
    }

    public function view(User $actor, Application $application): bool
    {
        return $this->adminCan($actor, 'applications.view')
            || ($actor->isContributor() && $application->user_id === $actor->getKey());
    }

    public function create(User $actor): bool
    {
        return $actor->isActiveContributor();
    }

    /** Shortlist and reject. */
    public function update(User $actor, Application $application): bool
    {
        return $this->adminCan($actor, 'applications.update');
    }

    /** Make an offer. */
    public function approve(User $actor, Application $application): bool
    {
        return $this->adminCan($actor, 'applications.approve');
    }

    public function withdraw(User $actor, Application $application): bool
    {
        return $actor->isContributor()
            && $application->user_id === $actor->getKey()
            && in_array($application->status, [ApplicationStatus::Submitted, ApplicationStatus::Shortlisted], true);
    }

    /** Accept or decline an offer. */
    public function respond(User $actor, Application $application): bool
    {
        return $actor->isActiveContributor()
            && $application->user_id === $actor->getKey()
            && $application->status === ApplicationStatus::Offered;
    }
}
