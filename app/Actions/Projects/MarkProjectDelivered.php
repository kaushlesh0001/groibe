<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/** in_qa -> delivered. */
class MarkProjectDelivered
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::Delivered, $actor);
    }
}
