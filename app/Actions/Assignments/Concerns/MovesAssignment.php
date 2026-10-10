<?php

namespace App\Actions\Assignments\Concerns;

use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/** Shared transition mechanics for spec 6.6: lock, validate the move, persist, log. Callers authorize first. */
trait MovesAssignment
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(Assignment): void|null  $after  runs inside the transaction once the new status is saved
     */
    protected function moveAssignment(
        Assignment $assignment,
        AssignmentStatus $to,
        User $actor,
        array $attributes = [],
        array $properties = [],
        ?Closure $after = null,
    ): Assignment {
        DB::transaction(function () use ($assignment, $to, $actor, $attributes, $properties, $after): void {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('assignment', $from->value, $to->value);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('assignment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'project_id' => $locked->project_id, ...$properties])
                ->log("Assignment moved from {$from->value} to {$to->value}");

            if ($after !== null) {
                $after($locked);
            }
        });

        return $assignment->refresh();
    }

    /** Invites are answered while the project is still being staffed or run (SCR-014). */
    protected function ensureProjectTakesAssignments(Project $project, string $what): void
    {
        if (! in_array($project->status, [ProjectStatus::Staffing, ProjectStatus::Active], true)) {
            throw ActionBlocked::guardFailed("{$what} is only possible while the project is staffing or active.");
        }
    }
}
