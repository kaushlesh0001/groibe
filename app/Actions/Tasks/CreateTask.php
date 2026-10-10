<?php

namespace App\Actions\Tasks;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Adds a task to a milestone that is still being worked (spec 6.7, 6.9). It starts as `todo`. */
class CreateTask
{
    use GuardsWorkPlan;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    /** @param  array{title: string, description?: string|null, estimate_hours?: numeric|null, due_date?: mixed, assignment_id?: int|string|null}  $data */
    public function __invoke(Milestone $milestone, User $actor, array $data): Task
    {
        Gate::forUser($actor)->authorize('create', Task::class);

        $task = DB::transaction(function () use ($milestone, $data): Task {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            $this->ensureProjectIsPlannable($locked->project);

            if (! in_array($locked->status, [MilestoneStatus::Planned, MilestoneStatus::InProgress], true)) {
                throw ActionBlocked::guardFailed('Tasks can only be added to a milestone that is planned or in progress.');
            }

            $assignment = $this->resolveAssignment($locked->project, $data['assignment_id'] ?? null);

            return Task::query()->create([
                'milestone_id' => $locked->getKey(),
                'project_id' => $locked->project_id,
                'assignment_id' => $assignment?->getKey(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'estimate_hours' => $data['estimate_hours'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'status' => TaskStatus::Todo,
            ]);
        });

        $task->loadMissing('assignment.user', 'milestone.project');

        if ($task->assignment !== null) {
            $this->dispatcher->send($task->assignment->user, new TaskAssigned($task, $task->milestone->project));
        }

        return $task;
    }
}
