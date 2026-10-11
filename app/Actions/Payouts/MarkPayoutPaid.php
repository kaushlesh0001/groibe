<?php

namespace App\Actions\Payouts;

use App\Actions\Payouts\Concerns\MovesPayout;
use App\Enums\PayoutStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Payout;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * approved -> paid (SCR-024). Needs the transaction reference (UTR) and the payment date, and may record TDS. The
 * reference is letters and digits only: bank account and card numbers must never be stored here (spec 17.2).
 */
class MarkPayoutPaid
{
    use MovesPayout;

    public function __invoke(Payout $payout, User $actor, string $reference, mixed $paidOn, mixed $tds = null): Payout
    {
        Gate::forUser($actor)->authorize('markPaid', $payout);

        $reference = trim($reference);

        if (! preg_match('/^[A-Za-z0-9]{6,30}$/', $reference)) {
            throw ActionBlocked::guardFailed('Enter the transaction reference (UTR) using 6 to 30 letters and digits. Never enter a bank account or card number.');
        }

        try {
            $paidAt = CarbonImmutable::parse($paidOn)->startOfDay();
        } catch (Throwable) {
            throw ActionBlocked::guardFailed('Enter the date the payment was made.');
        }

        if ($paidAt->isFuture()) {
            throw ActionBlocked::guardFailed('The payment date cannot be in the future.');
        }

        $tdsAmount = filled($tds) ? Money::nonNegative($tds, 'TDS') : null;

        return $this->movePayout(
            $payout,
            PayoutStatus::Paid,
            $actor,
            ['reference' => $reference, 'paid_at' => $paidAt, 'tds_amount' => $tdsAmount],
            ['paid_on' => $paidAt->toDateString()],
            guard: function (Payout $locked) use ($tdsAmount): void {
                if ($tdsAmount !== null && bccomp($tdsAmount, (string) $locked->amount, 2) > 0) {
                    throw ActionBlocked::guardFailed('TDS cannot be more than the payout amount.');
                }
            },
        );
    }
}
