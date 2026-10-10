<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Project": client R org, delivery_manager CRUA, community_manager and finance R.
 * Contributors get "R if assigned (limited)" once assignments exist in Pass 4; until then they are denied.
 */
class ProjectPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isClient() || $this->adminCan($actor, 'projects.view');
    }

    /** Draft projects are internal until the SOW is sent, so clients do not see them. */
    public function view(User $actor, Project $project): bool
    {
        return $this->adminCan($actor, 'projects.view')
            || ($project->status !== ProjectStatus::Draft && $this->isMember($actor, $project->organization_id));
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'projects.create');
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->adminCan($actor, 'projects.update');
    }

    /** Every state transition in spec 6.2, including cancel (admin only). */
    public function transition(User $actor, Project $project): bool
    {
        return $this->adminCan($actor, 'projects.update');
    }

    public function delete(User $actor, Project $project): bool
    {
        return $this->adminCan($actor, 'projects.delete');
    }

    public function restore(User $actor, Project $project): bool
    {
        return $this->delete($actor, $project);
    }

    public function forceDelete(User $actor, Project $project): bool
    {
        return $this->delete($actor, $project);
    }
}
