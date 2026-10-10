<?php

namespace App\Services\Projects;

use App\Models\Project;

/**
 * Cross-domain guards for the project state machine (spec 6.2). The real implementation arrives with
 * assignments and milestones in Pass 4; until then the bound default refuses both.
 */
interface ProjectTransitionGuards
{
    /** staffing -> active requires at least one active assignment. */
    public function hasActiveAssignment(Project $project): bool;

    /** delivered -> accepted happens when all milestones are client-accepted. */
    public function allMilestonesAccepted(Project $project): bool;
}
