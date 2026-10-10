<?php

namespace App\Actions\Projects;

use App\Actions\Assignments\CompleteProjectAssignments;
use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectTransitionGuards;

/**
 * delivered -> accepted, once every milestone is client-accepted (spec 6.2), checked through the guards
 * interface. An admin can run it by hand; it also runs automatically when the last milestone is accepted,
 * on behalf of the user who triggered that (`automatic: true`, no permission check of its own).
 */
class AcceptProject
{
    use TransitionsProject;

    public function __construct(private ProjectTransitionGuards $guards, private CompleteProjectAssignments $completeAssignments) {}

    public function __invoke(Project $project, User $actor, bool $automatic = false): Project
    {
        return $this->moveProject(
            $project,
            ProjectStatus::Accepted,
            $actor,
            $automatic ? null : 'transition',
            properties: $automatic ? ['automatic' => true] : [],
            guard: function (Project $locked): void {
                if (! $this->guards->allMilestonesAccepted($locked)) {
                    throw ActionBlocked::guardFailed('All milestones must be accepted by the client first.');
                }
            },
            after: function (Project $locked) use ($actor): void {
                ($this->completeAssignments)($locked, $actor);
            },
        );
    }
}
