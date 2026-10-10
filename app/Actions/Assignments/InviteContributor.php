<?php

namespace App\Actions\Assignments;

use App\Enums\RateType;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AssignmentInvited;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;

/** Admin invites a contributor to a project (spec 6.2, 6.6, SCR-014). The seat starts as `invited`. */
class InviteContributor
{
    public function __construct(private SeatContributor $seat, private NotificationDispatcher $dispatcher) {}

    /** @param  array{role_title: string, rate_type?: RateType|string, agreed_amount?: numeric|null, start_date?: mixed, end_date?: mixed}  $data */
    public function __invoke(Project $project, User $contributor, User $actor, array $data): Assignment
    {
        Gate::forUser($actor)->authorize('invite', [Assignment::class, $project]);

        $assignment = ($this->seat)($project, $contributor, $actor, $data);

        $this->dispatcher->send($contributor, new AssignmentInvited($assignment, $project));

        return $assignment;
    }
}
