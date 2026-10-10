<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

/** staffing|active -> on_hold. Remembers the state it was held from so ResumeProject can return there (SCR-008). */
class PutProjectOnHold
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor, ?string $reason = null): Project
    {
        return $this->moveProject($project, ProjectStatus::OnHold, $actor, properties: ['reason' => $reason], guard: function (Project $locked): void {
            $locked->status_before_hold = $locked->status;
        });
    }
}
