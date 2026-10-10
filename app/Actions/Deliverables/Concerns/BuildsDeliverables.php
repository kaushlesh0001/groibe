<?php

namespace App\Actions\Deliverables\Concerns;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Milestone;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Collection;

/** Rules shared by creating and editing a deliverable (spec 3, 6.8, 6.9). */
trait BuildsDeliverables
{
    use GuardsWorkPlan;

    /** A deliverable is built before the milestone is shared; once shared it is what the client sees. */
    protected function ensureMilestoneIsBeingBuilt(Milestone $milestone): void
    {
        $this->ensureProjectIsPlannable($milestone->project);

        if (! in_array($milestone->status, [MilestoneStatus::InProgress, MilestoneStatus::InQa], true)) {
            throw ActionBlocked::guardFailed('Deliverables can only be changed while the milestone is in progress or in QA, before it is shared.');
        }
    }

    /**
     * Only qa_approved submissions of this milestone's tasks may be attached (spec 6.8).
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Submission>
     */
    protected function resolveApprovedSubmissions(Milestone $milestone, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ActionBlocked::guardFailed('Pick at least one approved submission for the deliverable.');
        }

        $submissions = Submission::query()
            ->whereIn('id', $ids)
            ->whereHas('task', fn ($tasks) => $tasks->where('milestone_id', $milestone->getKey()))
            ->where('status', SubmissionStatus::QaApproved->value)
            ->get();

        if ($submissions->count() !== count($ids)) {
            throw ActionBlocked::guardFailed('Only approved submissions of this milestone can be attached to a deliverable.');
        }

        return $submissions;
    }
}
