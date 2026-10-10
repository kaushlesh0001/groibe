<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectTransitionGuards;

/** staffing -> active. Requires at least one active assignment (spec 6.2), checked through the guards interface. */
class ActivateProject
{
    use TransitionsProject;

    public function __construct(private ProjectTransitionGuards $guards) {}

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::Active, $actor, guard: function (Project $locked): void {
            if (! $this->guards->hasActiveAssignment($locked)) {
                throw ActionBlocked::guardFailed('A project needs at least one active assignment before it can go active.');
            }
        });
    }
}
