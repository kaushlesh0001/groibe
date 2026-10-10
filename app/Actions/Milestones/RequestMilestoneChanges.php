<?php

namespace App\Actions\Milestones;

use App\Actions\Milestones\Concerns\RespondsToMilestone;
use App\Enums\MilestoneStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * shared -> client_changes_requested with a change note, by a client owner or manager (spec 6.9, 12).
 * On a delivered project the project returns to `in_qa` (spec 6.2, SCR-016).
 */
class RequestMilestoneChanges
{
    use RespondsToMilestone;

    public function __invoke(Milestone $milestone, User $actor, string $note): Milestone
    {
        Gate::forUser($actor)->authorize('respond', $milestone);

        $note = trim($note);

        if ($note === '') {
            throw ActionBlocked::guardFailed('Tell us what needs to change.');
        }

        return $this->respond($milestone, $actor, MilestoneStatus::ClientChangesRequested, $note);
    }
}
