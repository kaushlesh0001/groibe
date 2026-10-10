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

    /** in_qa -> delivered is allowed once every milestone is shared or later (and there is at least one). */
    public function allMilestonesShared(Project $project): bool;

    /** delivered -> accepted happens when all milestones are client-accepted. */
    public function allMilestonesAccepted(Project $project): bool;
}
