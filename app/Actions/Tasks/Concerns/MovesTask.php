<?php

namespace App\Actions\Tasks\Concerns;

use App\Actions\Milestones\SyncMilestoneStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Contributor-side task transitions (spec 6.7): lock the task, re-check that the actor still holds an
 * active seat on a project that is being worked, validate the edge, persist, log, and let the milestone
 * follow (spec 6.9). Callers authorize first.
 */
trait MovesTask
{
    /**
     * @param  TaskStatus  $from  the only state this Action may start from
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(Task): void|null  $within  runs inside the transaction before the status is saved
     */
    protected function moveTask(Task $task, TaskStatus $from, TaskStatus $to, User $actor, array $properties = [], ?Closure $within = null): Task
    {
        DB::transaction(function () use ($task, $from, $to, $actor, $properties, $within): void {
            $locked = Task::query()->lockForUpdate()->with('milestone.project')->findOrFail($task->getKey());

            $seat = Assignment::query()->find($locked->assignment_id);

            if ($seat === null || $seat->user_id !== $actor->getKey() || $seat->status !== AssignmentStatus::Active) {
                throw ActionBlocked::guardFailed('This task is no longer assigned to you.');
            }

            if (! in_array($locked->milestone->project->status, [ProjectStatus::Active, ProjectStatus::InQa], true)) {
                throw ActionBlocked::guardFailed('Work can only be started and submitted while the project is active.');
            }

            if ($locked->status !== $from || ! $locked->status->canTransitionTo($to)) {
                throw IllegalTransition::for('task', $locked->status->value, $to->value);
            }

            if ($within !== null) {
                $within($locked);
            }

            $locked->forceFill(['status' => $to])->save();

            activity('task')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'project_id' => $locked->project_id, ...$properties])
                ->log("Task moved from {$from->value} to {$to->value}");

            app(SyncMilestoneStatus::class)($locked->milestone);
        });

        return $task->refresh();
    }
}
