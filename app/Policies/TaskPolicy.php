<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Milestone / Task": delivery_manager CRUD, contributor R on own tasks (status updates arrive
 * with My Work), client R org on tasks of milestones already shared with them.
 */
class TaskPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $actor->isClient() || $this->adminCan($actor, 'tasks.view');
    }

    public function view(User $actor, Task $task): bool
    {
        if ($this->adminCan($actor, 'tasks.view')) {
            return true;
        }

        $task->loadMissing('assignment', 'milestone.project');

        if ($actor->isClient()) {
            return $task->milestone->status->isClientVisible() && $this->isMember($actor, $task->milestone->project->organization_id);
        }

        return $actor->isContributor() && $task->assignment?->user_id === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'tasks.create');
    }

    public function update(User $actor, Task $task): bool
    {
        return $this->adminCan($actor, 'tasks.update');
    }

    /** Assign, reassign or unassign a task. */
    public function assign(User $actor, Task $task): bool
    {
        return $this->adminCan($actor, 'tasks.update');
    }

    public function delete(User $actor, Task $task): bool
    {
        return $this->adminCan($actor, 'tasks.delete');
    }
}
