<?php

namespace App\Actions\Payouts\Concerns;

use App\Enums\PayoutStatus;
use App\Enums\RateType;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Payout;

/** Checks shared by creating and editing a payout (SCR-024). Callers have already authorized and hold a lock on the assignment. */
trait GuardsPayoutAmounts
{
    /**
     * For a fixed fee seat, payouts that are not cancelled may not add up to more than the agreed amount.
     * Hourly seats have no cap.
     */
    protected function ensureWithinAgreedAmount(Assignment $assignment, string $amount, ?Payout $except = null): void
    {
        if ($assignment->rate_type !== RateType::Fixed) {
            return;
        }

        $committed = (string) Payout::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('status', '!=', PayoutStatus::Cancelled->value)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->sum('amount');

        $total = bcadd($committed, $amount, 2);

        if (bccomp($total, (string) $assignment->agreed_amount, 2) > 0) {
            throw ActionBlocked::guardFailed(
                "Payouts for this seat would total {$total}, which is above the agreed amount of {$assignment->agreed_amount}. Change the amount or cancel an earlier payout.",
            );
        }
    }

    /** A milestone link is optional, and when given it must belong to the payout's project. */
    protected function ensureMilestoneBelongs(?int $milestoneId, int $projectId): void
    {
        if ($milestoneId !== null && ! Milestone::query()->whereKey($milestoneId)->where('project_id', $projectId)->exists()) {
            throw ActionBlocked::guardFailed('That milestone does not belong to this project.');
        }
    }
}
