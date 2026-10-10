<?php

namespace App\Actions\Work\Concerns;

use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;

/** Rules shared by the milestone and task Actions that plan the work of a project (spec 6.9). */
trait GuardsWorkPlan
{
    /** Closed and cancelled projects keep their plan as it was. */
    protected function ensureProjectIsPlannable(Project $project): void
    {
        if (in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Cancelled], true)) {
            throw ActionBlocked::guardFailed("The plan of a {$project->status->value} project can no longer be changed.");
        }
    }

    /** A task goes to an active seat on the same project (spec 6.6, 7.3). */
    protected function resolveAssignment(Project $project, int|string|null $assignmentId): ?Assignment
    {
        if (blank($assignmentId)) {
            return null;
        }

        $assignment = Assignment::query()
            ->where('project_id', $project->getKey())
            ->whereKey($assignmentId)
            ->first();

        if ($assignment === null || $assignment->status !== AssignmentStatus::Active) {
            throw ActionBlocked::guardFailed('Tasks can only be assigned to an active member of this project.');
        }

        return $assignment;
    }
}
