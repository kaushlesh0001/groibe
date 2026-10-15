<?php

namespace App\Actions\Milestones;

use App\Actions\Milestones\Concerns\RespondsToMilestone;
use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\Gate;

/**
 * shared -> client_accepted, by a client owner or manager (spec 6.9, 12). When this is the last milestone
 * of a delivered project, the project moves to `accepted` (spec 6.2).
 */
class AcceptMilestone
{
    use RespondsToMilestone;

    public function __invoke(Milestone $milestone, User $actor): Milestone
    {
        Gate::forUser($actor)->authorize('respond', $milestone);
        app(Impersonation::class)->assertNotImpersonating();

        return $this->respond($milestone, $actor, MilestoneStatus::ClientAccepted, null);
    }
}
