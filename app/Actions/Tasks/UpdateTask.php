<?php

namespace App\Actions\Tasks;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Edits the planning fields of a task. Status and assignment change only through their own Actions. */
class UpdateTask
{
    use GuardsWorkPlan;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    /** @param  array{title?: string, description?: string|null, estimate_hours?: numeric|null, due_date?: mixed}  $data */
    public function __invoke(Task $task, User $actor, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        $changed = DB::transaction(function () use ($task, $data): array {
            $locked = Task::query()->lockForUpdate()->with('milestone.project')->findOrFail($task->getKey());

            $this->ensureProjectIsPlannable($locked->milestone->project);

            if ($locked->status === TaskStatus::Approved) {
                throw ActionBlocked::immutable('An approved task');
            }

            $locked->fill(array_intersect_key($data, array_flip(['title', 'description', 'estimate_hours', 'due_date'])))->save();

            return array_keys($locked->getChanges());
        });

        $task->refresh()->loadMissing('assignment.user', 'milestone.project');

        if ($task->assignment !== null && array_intersect($changed, ['title', 'description', 'estimate_hours', 'due_date']) !== []) {
            $this->dispatcher->send($task->assignment->user, new TaskChanged($task, $task->milestone->project, 'The task details were updated.'));
        }

        return $task;
    }
}
