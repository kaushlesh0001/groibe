<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DeliverableShared;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * in_qa -> shared, an explicit admin action (spec 6.9). Only approved work is shared with the client
 * (spec 2.1): every task must be approved and the milestone needs a deliverable with approved submissions.
 */
class ShareMilestone
{
    use GuardsWorkPlan;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Milestone $milestone, User $actor): Milestone
    {
        Gate::forUser($actor)->authorize('share', $milestone);

        DB::transaction(function () use ($milestone, $actor): void {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            $this->ensureProjectIsPlannable($locked->project);

            if (! $locked->status->canTransitionTo(MilestoneStatus::Shared)) {
                throw IllegalTransition::for('milestone', $locked->status->value, MilestoneStatus::Shared->value);
            }

            if (! in_array($locked->project->status, [ProjectStatus::Active, ProjectStatus::InQa], true)) {
                throw ActionBlocked::guardFailed('A milestone can only be shared while the project is active or in QA.');
            }

            if (Task::query()->where('milestone_id', $locked->getKey())->where('status', '!=', TaskStatus::Approved->value)->exists()) {
                throw ActionBlocked::guardFailed('Every task on the milestone must be approved before it is shared.');
            }

            if (! Deliverable::query()->where('milestone_id', $locked->getKey())->whereHas('submissions')->exists()) {
                throw ActionBlocked::guardFailed('Add a deliverable with approved work before sharing the milestone.');
            }

            // A fresh round for the client: any earlier answer and note are cleared (history stays in the activity log).
            $locked->forceFill([
                'status' => MilestoneStatus::Shared,
                'shared_at' => now(),
                'client_note' => null,
                'client_accepted_at' => null,
                'client_accepted_by' => null,
            ])->save();

            Deliverable::query()->where('milestone_id', $locked->getKey())->update(['shared_at' => now()]);

            activity('milestone')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('shared')
                ->withProperties(['from' => 'in_qa', 'to' => 'shared', 'project_id' => $locked->project_id])
                ->log('Milestone shared with the client');
        });

        $milestone->refresh()->loadMissing('project.organization');

        $this->dispatcher->toOrganization($milestone->project->organization, new DeliverableShared($milestone, $milestone->project));

        return $milestone;
    }
}
