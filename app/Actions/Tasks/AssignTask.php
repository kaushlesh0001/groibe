<?php

namespace App\Actions\Tasks;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Assigns, reassigns or unassigns (null) a task that has not started. Work already in flight moves
 * only by removing the contributor, which returns their tasks to `todo` (spec 6.2).
 */
class AssignTask
{
    use GuardsWorkPlan;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Task $task, User $actor, int|string|null $assignmentId): Task
    {
        Gate::forUser($actor)->authorize('assign', $task);

        [$previous, $current] = DB::transaction(function () use ($task, $actor, $assignmentId): array {
            $locked = Task::query()->lockForUpdate()->with('milestone.project')->findOrFail($task->getKey());

            $this->ensureProjectIsPlannable($locked->milestone->project);

            if ($locked->status !== TaskStatus::Todo) {
                throw ActionBlocked::guardFailed('Only a task that has not started can be assigned or reassigned.');
            }

            $assignment = $this->resolveAssignment($locked->milestone->project, $assignmentId);
            $previous = $locked->assignment_id;

            if ($previous !== $assignment?->getKey()) {
                $locked->forceFill(['assignment_id' => $assignment?->getKey()])->save();

                activity('task')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->event('assigned')
                    ->withProperties(['from_assignment_id' => $previous, 'to_assignment_id' => $assignment?->getKey()])
                    ->log($assignment === null ? 'Task unassigned' : 'Task assigned');
            }

            return [$previous, $assignment?->getKey()];
        });

        $task->refresh()->loadMissing('milestone.project');

        if ($previous !== $current) {
            if ($current !== null) {
                $this->dispatcher->send(Assignment::query()->with('user')->findOrFail($current)->user, new TaskAssigned($task, $task->milestone->project));
            }

            if ($previous !== null) {
                $this->dispatcher->send(Assignment::query()->with('user')->findOrFail($previous)->user, new TaskChanged($task, $task->milestone->project, 'The task was given to someone else.'));
            }
        }

        return $task;
    }
}
