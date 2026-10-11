<?php

namespace App\Actions\Payouts;

use App\Actions\Payouts\Concerns\MovesPayout;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** planned -> approved (SCR-024). From now on the figures are locked and the contributor can see the payout. */
class ApprovePayout
{
    use MovesPayout;

    public function __invoke(Payout $payout, User $actor): Payout
    {
        Gate::forUser($actor)->authorize('approve', $payout);

        return $this->movePayout($payout, PayoutStatus::Approved, $actor, ['approved_at' => now()]);
    }
}
