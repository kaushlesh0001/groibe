<?php

namespace App\Actions\Assignments;

use App\Actions\Assignments\Concerns\MovesAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AssignmentRemoved;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/**
 * active -> removed, by an admin with a reason (spec 6.6). The contributor's unfinished tasks return to
 * unassigned with status `todo` and the delivery manager is notified (spec 6.2, SCR-014).
 */
class RemoveAssignment
{
    use MovesAssignment;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Assignment $assignment, User $actor, string $reason): Assignment
    {
        Gate::forUser($actor)->authorize('remove', $assignment);

        if (blank($reason)) {
            throw ActionBlocked::guardFailed('A reason is required to remove someone from a project.');
        }

        $returned = 0;

        $assignment = $this->moveAssignment(
            $assignment,
            AssignmentStatus::Removed,
            $actor,
            ['removed_reason' => $reason],
            ['reason' => $reason],
            after: function (Assignment $locked) use (&$returned, $actor): void {
                $tasks = Task::query()
                    ->where('assignment_id', $locked->getKey())
                    ->where('status', '!=', TaskStatus::Approved->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($tasks as $task) {
                    $task->forceFill(['assignment_id' => null, 'status' => TaskStatus::Todo])->save();

                    activity('task')
                        ->causedBy($actor)
                        ->performedOn($task)
                        ->event('unassigned')
                        ->withProperties(['assignment_id' => $locked->getKey(), 'reason' => 'assignment_removed'])
                        ->log('Task returned to unassigned');
                }

                $returned = $tasks->count();
            },
        );

        $assignment->loadMissing('project.deliveryManager', 'user');
        $notification = new AssignmentRemoved($assignment, $assignment->project, $assignment->user->name, $returned);

        $this->dispatcher->send($assignment->user, $notification);
        $this->dispatcher->toAdmins('projects.update', $notification, $assignment->project->deliveryManager);

        return $assignment;
    }
}
