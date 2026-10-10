<?php

namespace App\Actions\Applications\Concerns;

use App\Enums\ApplicationStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Application;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/** Shared transition mechanics for spec 6.5: lock, validate the move, persist, log. Callers authorize first. */
trait MovesApplication
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(Application): void|null  $after  runs inside the transaction once the new status is saved
     */
    protected function moveApplication(
        Application $application,
        ApplicationStatus $to,
        User $actor,
        array $attributes = [],
        array $properties = [],
        ?Closure $after = null,
    ): Application {
        DB::transaction(function () use ($application, $to, $actor, $attributes, $properties, $after): void {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('application', $from->value, $to->value);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('application')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'open_role_id' => $locked->open_role_id, ...$properties])
                ->log("Application moved from {$from->value} to {$to->value}");

            if ($after !== null) {
                $after($locked);
            }
        });

        return $application->refresh();
    }
}
