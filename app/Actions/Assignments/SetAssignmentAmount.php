<?php

namespace App\Actions\Assignments;

use App\Enums\AssignmentStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Payout;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets or changes the agreed amount of a seat (SCR-024, SUGGESTIONS 130). Payouts are planned against it, so it cannot drop
 * below what is already committed, and it cannot change at all on a seat that was removed or declined.
 */
class SetAssignmentAmount
{
    public function __invoke(Assignment $assignment, User $actor, mixed $amount): Assignment
    {
        Gate::forUser($actor)->authorize('setAmount', $assignment);

        $amount = Money::positive($amount, 'The agreed amount');

        DB::transaction(function () use ($assignment, $actor, $amount): void {
            $locked = Assignment::query()->lockForUpdate()->findOrFail($assignment->getKey());

            if (! in_array($locked->status, [AssignmentStatus::Invited, AssignmentStatus::Active, AssignmentStatus::Completed], true)) {
                throw ActionBlocked::guardFailed('The agreed amount can only be set on a seat that is invited, active or completed.');
            }

            $committed = (string) Payout::query()
                ->where('assignment_id', $locked->getKey())
                ->where('status', '!=', PayoutStatus::Cancelled->value)
                ->sum('amount');

            if (bccomp($amount, $committed, 2) < 0) {
                throw ActionBlocked::guardFailed("Payouts of {$committed} are already planned or paid for this seat. The agreed amount cannot be lower than that.");
            }

            $from = $locked->agreed_amount;
            $locked->forceFill(['agreed_amount' => $amount])->save();

            activity('assignment')->causedBy($actor)->performedOn($locked)->event('amount_set')
                ->withProperties(['project_id' => $locked->project_id, 'from' => $from === null ? null : (string) $from, 'to' => $amount])
                ->log('Agreed amount set');
        });

        return $assignment->refresh();
    }
}
