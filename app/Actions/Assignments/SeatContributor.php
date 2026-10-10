<?php

namespace App\Actions\Assignments;

use App\Actions\Assignments\Concerns\MovesAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\RateType;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a contributor on a project: the one place the seat rules live (spec 6.2, 6.6, 7.4, SCR-014). Admin invites
 * start as `invited`; an accepted open-role offer (spec 6.5) starts as `active`. Callers authorize first.
 */
class SeatContributor
{
    use MovesAssignment;

    /** @param  array{role_title: string, rate_type?: RateType|string, agreed_amount?: numeric|null, start_date?: mixed, end_date?: mixed}  $data */
    public function __invoke(Project $project, User $contributor, User $actor, array $data, AssignmentStatus $status = AssignmentStatus::Invited): Assignment
    {
        return DB::transaction(function () use ($project, $contributor, $actor, $data, $status): Assignment {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $this->ensureProjectTakesAssignments($locked, $status === AssignmentStatus::Invited ? 'Inviting a contributor' : 'Joining the project');

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
                'status' => $status,
                'rate_type' => $data['rate_type'] ?? RateType::Fixed,
                'agreed_amount' => $data['agreed_amount'] ?? null,
                'currency' => $locked->currency,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'invited_at' => now(),
                'accepted_at' => $status === AssignmentStatus::Active ? now() : null,
            ]);

            activity('assignment')
                ->causedBy($actor)
                ->performedOn($assignment)
                ->event($status === AssignmentStatus::Invited ? 'invited' : 'seated')
                ->withProperties(['project_id' => $locked->getKey(), 'user_id' => $contributor->getKey(), 'role_title' => $assignment->role_title, 'status' => $status->value])
                ->log($status === AssignmentStatus::Invited ? 'Contributor invited to project' : 'Contributor joined project from an accepted offer');

            return $assignment;
        });
    }
}
