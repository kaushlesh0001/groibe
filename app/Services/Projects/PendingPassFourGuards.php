<?php

namespace App\Services\Projects;

use App\Models\Project;

/** Default binding until Pass 4 replaces it: assignments and milestones do not exist yet, so both guards fail. */
class PendingPassFourGuards implements ProjectTransitionGuards
{
    public function hasActiveAssignment(Project $project): bool
    {
        return false;
    }

    public function allMilestonesAccepted(Project $project): bool
    {
        return false;
    }
}
