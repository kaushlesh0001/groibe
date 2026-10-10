<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectTransitionGuards;

/** delivered -> accepted, once every milestone is client-accepted (spec 6.2), checked through the guards interface. */
class AcceptProject
{
    use TransitionsProject;

    public function __construct(private ProjectTransitionGuards $guards) {}

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::Accepted, $actor, guard: function (Project $locked): void {
            if (! $this->guards->allMilestonesAccepted($locked)) {
                throw ActionBlocked::guardFailed('All milestones must be accepted by the client first.');
            }
        });
    }
}
