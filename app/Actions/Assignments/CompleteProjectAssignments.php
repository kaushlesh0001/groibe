<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;

/**
 * Active assignments become `completed` when the project reaches `accepted` (spec 6.2, SCR-014).
 * Runs inside the project transition's transaction, so it carries no authorization of its own.
 */
class CompleteProjectAssignments
{
    public function __invoke(Project $project, ?User $actor): int
    {
        $assignments = Assignment::query()
            ->where('project_id', $project->getKey())
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->forceFill(['status' => AssignmentStatus::Completed, 'completed_at' => now()])->save();

            activity('assignment')
                ->causedBy($actor)
                ->performedOn($assignment)
                ->event(AssignmentStatus::Completed->value)
                ->withProperties(['from' => AssignmentStatus::Active->value, 'to' => AssignmentStatus::Completed->value, 'project_id' => $project->getKey(), 'reason' => 'project_accepted'])
                ->log('Assignment completed with the project');
        }

        return $assignments->count();
    }
}
