<?php

namespace App\Actions\Briefs\Concerns;

use App\Enums\BriefStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Brief;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared transition mechanics for spec 6.1: authorize, lock, validate the move, persist, log. */
trait TransitionsBrief
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry
     * @param  Closure(Brief): void|null  $guard  runs inside the transaction after the state check
     */
    protected function move(
        Brief $brief,
        BriefStatus $to,
        User $actor,
        string $ability,
        array $attributes = [],
        array $properties = [],
        ?Closure $guard = null,
    ): Brief {
        Gate::forUser($actor)->authorize($ability, $brief);

        DB::transaction(function () use ($brief, $to, $actor, $attributes, $properties, $guard): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('brief', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('brief')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, ...$properties])
                ->log("Brief moved from {$from->value} to {$to->value}");
        });

        return $brief->refresh();
    }
}
