<?php

namespace App\Actions\Tasks;

use App\Actions\Tasks\Concerns\MovesTask;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** todo -> in_progress, by the assignee. The first start moves the milestone to in progress (spec 6.9). */
class StartTask
{
    use MovesTask;

    public function __invoke(Task $task, User $actor): Task
    {
        Gate::forUser($actor)->authorize('work', $task);

        return $this->moveTask($task, TaskStatus::Todo, TaskStatus::InProgress, $actor);
    }
}
