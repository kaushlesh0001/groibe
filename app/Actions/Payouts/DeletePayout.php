<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Deletes a payout that was only planned. Anything approved, paid or cancelled stays as evidence (SCR-024). */
class DeletePayout
{
    public function __invoke(Payout $payout, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $payout);

        DB::transaction(function () use ($payout, $actor): void {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->getKey());

            if ($locked->status !== PayoutStatus::Planned || $locked->approved_at !== null) {
                throw ActionBlocked::guardFailed('Only a payout that is still planned can be deleted. Cancel it instead.');
            }

            activity('payout')->causedBy($actor)->performedOn($locked)->event('deleted')
                ->withProperties(['project_id' => $locked->project_id, 'amount' => (string) $locked->amount])->log('Planned payout deleted');

            $locked->delete();
        });
    }
}
