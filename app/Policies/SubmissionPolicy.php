<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Submission": contributor CR own, delivery_manager RUA, client R shared only. Client access to
 * shared submissions arrives with deliverables (step 6); until then clients see none.
 */
class SubmissionPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $this->adminCan($actor, 'submissions.view');
    }

    public function view(User $actor, Submission $submission): bool
    {
        return $this->adminCan($actor, 'submissions.view')
            || ($actor->isContributor() && $submission->user_id === $actor->getKey());
    }
}
