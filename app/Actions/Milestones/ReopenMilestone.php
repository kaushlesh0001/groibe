<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * client_changes_requested -> in_progress, by an admin (spec 6.9). The client's note stays on the
 * milestone for the team. Follow-up work is added as new tasks; once every task is submitted or approved
 * the milestone goes back to QA by the usual rule, and an admin shares it again.
 */
class ReopenMilestone
{
    use GuardsWorkPlan;

    public function __construct(private SyncMilestoneStatus $sync) {}

    public function __invoke(Milestone $milestone, User $actor): Milestone
    {
        Gate::forUser($actor)->authorize('reopen', $milestone);

        DB::transaction(function () use ($milestone, $actor): void {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            $this->ensureProjectIsPlannable($locked->project);

            if (! $locked->status->canTransitionTo(MilestoneStatus::InProgress) || $locked->status !== MilestoneStatus::ClientChangesRequested) {
                throw IllegalTransition::for('milestone', $locked->status->value, MilestoneStatus::InProgress->value);
            }

            $locked->forceFill(['status' => MilestoneStatus::InProgress])->save();

            activity('milestone')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('reopened')
                ->withProperties(['from' => 'client_changes_requested', 'to' => 'in_progress', 'project_id' => $locked->project_id])
                ->log('Milestone reopened after client changes');

            ($this->sync)($locked);
        });

        return $milestone->refresh();
    }
}
