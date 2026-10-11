<?php

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;
use App\Services\FeatureFlags\FeatureFlags;

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

    /**
     * Showcase consent and client reviews of contributors (spec 9, 12): only the client's owner and manager, once the
     * project is accepted, and still open after it is closed so the client can change their mind (Pass 6 decision).
     */
    public function manageShowcase(User $actor, Project $project): bool
    {
        return in_array($project->status, [ProjectStatus::Accepted, ProjectStatus::Closed], true)
            && $this->isOwnerOrManager($actor, $project->organization_id);
    }

    /** Writing client_to_contributor reviews: the same people and states as showcase consent. */
    public function reviewContributors(User $actor, Project $project): bool
    {
        return $this->manageShowcase($actor, $project);
    }

    /** A contributor reviews the client only after the project is closed, and only if their own seat completed (spec 9). */
    public function reviewClient(User $actor, Project $project): bool
    {
        return $actor->isActiveContributor()
            && $project->status === ProjectStatus::Closed
            && Assignment::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', AssignmentStatus::Completed->value)
                ->exists();
    }

    /** Opening the project's conversation (spec 14): the same people as `Thread::visibleTo`, while messaging is on. */
    public function message(User $actor, Project $project): bool
    {
        return app(FeatureFlags::class)->enabled(FeatureFlag::Messaging)
            && Project::query()->messageableBy($actor)->whereKey($project->getKey())->exists();
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
