<?php

namespace App\Actions\Assignments;

use App\Actions\Assignments\Concerns\MovesAssignment;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\User;
use App\Notifications\AssignmentResponded;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** invited -> active, by the invited contributor (spec 6.6). */
class AcceptAssignment
{
    use MovesAssignment;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Assignment $assignment, User $actor): Assignment
    {
        Gate::forUser($actor)->authorize('respond', $assignment);

        $assignment->loadMissing('project.deliveryManager');
        $this->ensureProjectTakesAssignments($assignment->project, 'Accepting an invite');

        $assignment = $this->moveAssignment($assignment, AssignmentStatus::Active, $actor, ['accepted_at' => now()]);

        $this->dispatcher->toAdmins('projects.update', new AssignmentResponded($assignment, $assignment->project, $actor->name, accepted: true), $assignment->project->deliveryManager);

        return $assignment;
    }
}
