<?php

namespace App\Policies;

use App\Models\Deliverable;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Deliverable": delivery_manager CRU (no delete), client R org and accept/changes (via the
 * milestone), contributors none. A client reads a deliverable only once its milestone is shared.
 */
class DeliverablePolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isClient() || $this->adminCan($actor, 'deliverables.view');
    }

    public function view(User $actor, Deliverable $deliverable): bool
    {
        if ($this->adminCan($actor, 'deliverables.view')) {
            return true;
        }

        $deliverable->loadMissing('milestone.project');

        return $actor->isClient()
            && $deliverable->milestone->status->isClientVisible()
            && $this->isMember($actor, $deliverable->milestone->project->organization_id);
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'deliverables.create');
    }

    public function update(User $actor, Deliverable $deliverable): bool
    {
        return $this->adminCan($actor, 'deliverables.update');
    }
}
