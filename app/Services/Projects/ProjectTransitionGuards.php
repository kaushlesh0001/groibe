<?php

namespace App\Services\Projects;

use App\Models\Project;

/**
 * Cross-domain guards for the project state machine (spec 6.2). Bound to EloquentProjectTransitionGuards;
 * tests swap in a fake to isolate the project state machine.
 */
interface ProjectTransitionGuards
{
    /** staffing -> active requires at least one active assignment. */
    public function hasActiveAssignment(Project $project): bool;

    /** delivered -> accepted happens when all milestones are client-accepted. */
    public function allMilestonesAccepted(Project $project): bool;
}
