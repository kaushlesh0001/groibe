<?php

namespace App\Actions\Assignments;

use App\Actions\Assignments\Concerns\MovesAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\RateType;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AssignmentInvited;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Admin invites a contributor to a project (spec 6.2, 6.6, SCR-014). The seat starts as `invited`. */
class InviteContributor
{
    use MovesAssignment;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    /** @param  array{role_title: string, rate_type?: RateType|string, agreed_amount?: numeric|null, start_date?: mixed, end_date?: mixed}  $data */
    public function __invoke(Project $project, User $contributor, User $actor, array $data): Assignment
    {
        Gate::forUser($actor)->authorize('invite', [Assignment::class, $project]);

        $assignment = DB::transaction(function () use ($project, $contributor, $actor, $data): Assignment {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $this->ensureProjectTakesAssignments($locked, 'Inviting a contributor');

            $contributor->loadMissing('profile');

            if (! $contributor->isActiveContributor()) {
                throw ActionBlocked::guardFailed('Only active contributors can be assigned to a project.');
            }

            $alreadySeated = Assignment::query()
                ->where('project_id', $locked->getKey())
                ->forUser($contributor)
                ->open()
                ->exists();

            if ($alreadySeated) {
                throw ActionBlocked::guardFailed('This contributor already holds a seat on the project.');
            }

            $assignment = Assignment::query()->create([
                'project_id' => $locked->getKey(),
                'user_id' => $contributor->getKey(),
                'role_title' => $data['role_title'],
                'status' => AssignmentStatus::Invited,
                'rate_type' => $data['rate_type'] ?? RateType::Fixed,
                'agreed_amount' => $data['agreed_amount'] ?? null,
                'currency' => $locked->currency,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'invited_at' => now(),
            ]);

            activity('assignment')
                ->causedBy($actor)
                ->performedOn($assignment)
                ->event('invited')
                ->withProperties(['project_id' => $locked->getKey(), 'user_id' => $contributor->getKey(), 'role_title' => $assignment->role_title])
                ->log('Contributor invited to project');

            return $assignment;
        });

        $this->dispatcher->send($contributor, new AssignmentInvited($assignment, $project));

        return $assignment;
    }
}
