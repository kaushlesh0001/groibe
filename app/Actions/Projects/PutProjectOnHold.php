<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/** staffing|active -> on_hold. Resume is not specified in spec 6.2 yet (SCR-008, proposed). */
class PutProjectOnHold
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor, ?string $reason = null): Project
    {
        return $this->moveProject($project, ProjectStatus::OnHold, $actor, properties: ['reason' => $reason]);
    }
}
