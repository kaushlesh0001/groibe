<?php

namespace App\Actions\Payouts\Concerns;

use App\Enums\PayoutStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\PayoutStatusChanged;
use App\Services\Notifications\NotificationDispatcher;
use Closure;
use Illuminate\Support\Facades\DB;

/** Shared mechanics for the payout transitions (spec 7.6, SCR-024): lock, validate the move, persist, log, tell the contributor. */
trait MovesPayout
{
    /**
     * @param  array<string, mixed>  $attributes  columns to write with the status change
     * @param  array<string, mixed>  $properties  extra data for the activity log entry (never notes or the reference)
     * @param  Closure(Payout): void|null  $guard  extra checks that run on the locked row before anything is written
     */
    protected function movePayout(
        Payout $payout,
        PayoutStatus $to,
        User $actor,
        array $attributes = [],
        array $properties = [],
        bool $notifyContributor = true,
        ?Closure $guard = null,
    ): Payout {
        $wasApproved = false;

        DB::transaction(function () use ($payout, $to, $actor, $attributes, $properties, $guard, &$wasApproved): void {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('payout', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $wasApproved = $locked->approved_at !== null;

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('payout')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'project_id' => $locked->project_id, ...$properties])
                ->log("Payout moved from {$from->value} to {$to->value}");
        });

        $payout->refresh()->loadMissing('user', 'project:id,code,title');

        // The contributor only ever heard of a payout once it was approved, so a cancelled plan stays silent.
        if ($notifyContributor && ($to !== PayoutStatus::Cancelled || $wasApproved)) {
            app(NotificationDispatcher::class)->send($payout->user, new PayoutStatusChanged($payout->project->title, $to));
        }

        return $payout;
    }
}
