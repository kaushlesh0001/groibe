<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/** active -> in_qa. */
class MoveProjectToQa
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::InQa, $actor, guard: $this->mustBeIn(ProjectStatus::Active, ProjectStatus::InQa));
    }
}
