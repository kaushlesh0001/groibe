<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Removes a milestone that has not started and holds no tasks, so no work or client-visible record is lost. */
class DeleteMilestone
{
    use GuardsWorkPlan;

    public function __invoke(Milestone $milestone, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $milestone);

        DB::transaction(function () use ($milestone): void {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            $this->ensureProjectIsPlannable($locked->project);

            if ($locked->status !== MilestoneStatus::Planned || $locked->tasks()->exists()) {
                throw ActionBlocked::guardFailed('Only a planned milestone with no tasks can be deleted.');
            }

            $locked->delete();
        });
    }
}
