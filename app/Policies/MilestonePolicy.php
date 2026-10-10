<?php

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Milestone / Task": delivery_manager CRUD, client R org (shared milestones only, spec 6.9),
 * contributor R on projects they work on.
 */
class MilestonePolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $actor->isClient() || $this->adminCan($actor, 'milestones.view');
    }

    public function view(User $actor, Milestone $milestone): bool
    {
        if ($this->adminCan($actor, 'milestones.view')) {
            return true;
        }

        if ($actor->isClient()) {
            $milestone->loadMissing('project');

            return $milestone->status->isClientVisible() && $this->isMember($actor, $milestone->project->organization_id);
        }

        return $actor->isContributor()
            && Assignment::query()
                ->where('project_id', $milestone->project_id)
                ->forUser($actor)
                ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])
                ->exists();
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'milestones.create');
    }

    public function update(User $actor, Milestone $milestone): bool
    {
        return $this->adminCan($actor, 'milestones.update');
    }

    public function reorder(User $actor): bool
    {
        return $this->adminCan($actor, 'milestones.update');
    }

    public function delete(User $actor, Milestone $milestone): bool
    {
        return $this->adminCan($actor, 'milestones.delete');
    }
}
