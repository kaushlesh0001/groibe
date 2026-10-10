<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\TasksOverdue;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Daily job (spec 17.1 scheduler): one digest per running project, to its delivery manager, listing the open
 * tasks past their due date. Only active and in-QA projects are checked; a project on hold or finished is quiet.
 * Falls back to every admin who can edit tasks when the project has no delivery manager.
 */
class NotifyOverdueTasks extends Command
{
    protected $signature = 'groibe:notify-overdue-tasks';

    protected $description = 'Notify delivery managers about tasks that are past their due date';

    /** Most overdue tasks listed per digest; the total is always stated. */
    public const LIST_LIMIT = 15;

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $digests = 0;

        Project::query()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InQa->value])
            ->whereHas('tasks', fn ($tasks) => $tasks->overdue())
            ->with('deliveryManager')
            ->chunkById(50, function ($projects) use ($dispatcher, &$digests): void {
                foreach ($projects as $project) {
                    $total = $project->tasks()->overdue()->count();

                    $listed = $project->tasks()->overdue()
                        ->with(['assignment:id,user_id', 'assignment.user:id,name'])
                        ->orderBy('due_date')
                        ->orderBy('id')
                        ->limit(self::LIST_LIMIT)
                        ->get()
                        ->map(fn (Task $task): array => [
                            'title' => $task->title,
                            'due' => $task->due_date->toFormattedDateString(),
                            'status' => $task->status->getLabel(),
                            'assignee' => $task->assignment?->user->name ?? 'Unassigned',
                        ])
                        ->all();

                    $dispatcher->toAdmins('tasks.update', new TasksOverdue($project, $total, $listed), $project->deliveryManager);
                    $digests++;
                }
            });

        $this->info("Sent {$digests} overdue task ".($digests === 1 ? 'digest' : 'digests').'.');

        return self::SUCCESS;
    }
}
