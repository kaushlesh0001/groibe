<?php

namespace App\Services\Projects;

use App\Enums\MilestoneStatus;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;

/** Database-backed guards for the project state machine (spec 6.2). */
class EloquentProjectTransitionGuards implements ProjectTransitionGuards
{
    public function hasActiveAssignment(Project $project): bool
    {
        return Assignment::query()->where('project_id', $project->getKey())->active()->exists();
    }

    /** A project with no milestones cannot be delivered: there is nothing to share. */
    public function allMilestonesShared(Project $project): bool
    {
        $milestones = Milestone::query()->where('project_id', $project->getKey());

        return (clone $milestones)->exists()
            && ! (clone $milestones)->whereNotIn('status', [
                MilestoneStatus::Shared->value,
                MilestoneStatus::ClientAccepted->value,
                MilestoneStatus::ClientChangesRequested->value,
            ])->exists();
    }

    /** A project with no milestones cannot be accepted: there is nothing the client accepted. */
    public function allMilestonesAccepted(Project $project): bool
    {
        $milestones = Milestone::query()->where('project_id', $project->getKey());

        return (clone $milestones)->exists()
            && ! (clone $milestones)->where('status', '!=', MilestoneStatus::ClientAccepted->value)->exists();
    }
}
