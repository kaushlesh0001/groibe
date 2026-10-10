<?php

namespace App\Actions\Tasks;

use App\Actions\Milestones\SyncMilestoneStatus;
use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Deletes a task that has not started and has no submissions, then re-evaluates its milestone. */
class DeleteTask
{
    use GuardsWorkPlan;

    public function __construct(private SyncMilestoneStatus $syncMilestone) {}

    public function __invoke(Task $task, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $task);

        DB::transaction(function () use ($task): void {
            $locked = Task::query()->lockForUpdate()->with('milestone.project')->findOrFail($task->getKey());

            $this->ensureProjectIsPlannable($locked->milestone->project);

            if ($locked->status !== TaskStatus::Todo || $locked->submissions()->exists()) {
                throw ActionBlocked::guardFailed('Only a task that has not started and has no submissions can be deleted.');
            }

            $locked->delete();

            ($this->syncMilestone)($locked->milestone);
        });
    }
}
