<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/** sow_accepted -> staffing. */
class StartStaffing
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::Staffing, $actor, guard: $this->mustBeIn(ProjectStatus::SowAccepted, ProjectStatus::Staffing));
    }
}
