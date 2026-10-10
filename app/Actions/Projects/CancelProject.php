<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;

/** Any pre-closed state -> cancelled. Admin only, reason required (spec 6.2). The reason lives in the activity log. */
class CancelProject
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor, string $reason): Project
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ActionBlocked::guardFailed('A reason is required to cancel a project.');
        }

        return $this->moveProject($project, ProjectStatus::Cancelled, $actor, properties: ['reason' => $reason]);
    }
}
