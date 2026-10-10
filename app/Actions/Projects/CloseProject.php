<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/**
 * accepted -> closed. Review prompts, proof-of-work drafts and the reputation recompute that spec 6.2
 * attaches to closing are built in Pass 6 and hook in here.
 */
class CloseProject
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor): Project
    {
        return $this->moveProject($project, ProjectStatus::Closed, $actor, attributes: ['closed_at' => now()]);
    }
}
