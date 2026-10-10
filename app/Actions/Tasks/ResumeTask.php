<?php

namespace App\Actions\Tasks;

use App\Actions\Tasks\Concerns\MovesTask;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** changes_requested -> in_progress, by the assignee, to start revising (spec 6.7). */
class ResumeTask
{
    use MovesTask;

    public function __invoke(Task $task, User $actor): Task
    {
        Gate::forUser($actor)->authorize('work', $task);

        return $this->moveTask($task, TaskStatus::ChangesRequested, TaskStatus::InProgress, $actor);
    }
}
