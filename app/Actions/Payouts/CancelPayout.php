<?php

namespace App\Actions\Payouts;

use App\Actions\Payouts\Concerns\MovesPayout;
use App\Enums\PayoutStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** planned or approved -> cancelled, with a reason kept in the audit log (SCR-024). A paid payout cannot be cancelled. */
class CancelPayout
{
    use MovesPayout;

    public function __invoke(Payout $payout, User $actor, string $reason): Payout
    {
        Gate::forUser($actor)->authorize('cancel', $payout);

        if (blank($reason)) {
            throw ActionBlocked::guardFailed('A reason is required to cancel a payout.');
        }

        return $this->movePayout($payout, PayoutStatus::Cancelled, $actor, [], ['reason' => trim($reason)]);
    }
}
