<?php

namespace App\Actions\Projects\Concerns;

use App\Enums\ProjectStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared transition mechanics for spec 6.2: authorize, lock, validate the move, persist, log. */
trait TransitionsProject
{
    /**
     * @param  string|null  $ability  policy ability to authorize; null when the calling Action already authorized (SOW actions)
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(Project): void|null  $guard  runs inside the transaction after the state check
     * @param  Closure(Project): void|null  $after  runs inside the transaction once the new status is saved
     */
    protected function moveProject(
        Project $project,
        ProjectStatus $to,
        ?User $actor,
        ?string $ability = 'transition',
        array $attributes = [],
        array $properties = [],
        ?Closure $guard = null,
        ?Closure $after = null,
    ): Project {
        if ($ability !== null && $actor !== null) {
            Gate::forUser($actor)->authorize($ability, $project);
        }

        DB::transaction(function () use ($project, $to, $actor, $attributes, $properties, $guard, $after): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('project', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('project')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, ...$properties])
                ->log("Project moved from {$from->value} to {$to->value}");

            if ($after !== null) {
                $after($locked);
            }
        });

        return $project->refresh();
    }
}
