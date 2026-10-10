<?php

namespace App\Actions\OpenRoles\Concerns;

use App\Enums\OpenRoleStatus;
use App\Exceptions\IllegalTransition;
use App\Models\OpenRole;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/** Shared transition mechanics for spec 6.4: lock, validate the move, persist, log. Callers authorize first. */
trait MovesOpenRole
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to write with the status change
     * @param  Closure(OpenRole): void|null  $guard  runs inside the transaction after the state check
     */
    protected function moveOpenRole(OpenRole $role, OpenRoleStatus $to, User $actor, array $attributes = [], ?Closure $guard = null): OpenRole
    {
        DB::transaction(function () use ($role, $to, $actor, $attributes, $guard): void {
            $locked = OpenRole::query()->lockForUpdate()->findOrFail($role->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('open role', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('open_role')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value])
                ->log("Open role moved from {$from->value} to {$to->value}");
        });

        return $role->refresh();
    }
}
