<?php

namespace App\Actions\Payouts;

use App\Actions\Payouts\Concerns\GuardsPayoutAmounts;
use App\Enums\PayoutStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Payout;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Edits a planned payout's amount, milestone or notes. Once approved the figures are locked: cancel and plan a new one (SCR-024). */
class UpdatePayout
{
    use GuardsPayoutAmounts;

    /** @param  array{amount?: mixed, milestone_id?: int|string|null, notes?: string|null}  $data */
    public function __invoke(Payout $payout, User $actor, array $data): Payout
    {
        Gate::forUser($actor)->authorize('update', $payout);

        return DB::transaction(function () use ($payout, $actor, $data): Payout {
            $seat = Assignment::query()->lockForUpdate()->findOrFail($payout->assignment_id);
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->getKey());

            if ($locked->status !== PayoutStatus::Planned) {
                throw ActionBlocked::guardFailed('Only a planned payout can be edited. Cancel this one and plan a new payout instead.');
            }

            $amount = array_key_exists('amount', $data) ? Money::positive($data['amount']) : (string) $locked->amount;
            $milestoneId = array_key_exists('milestone_id', $data)
                ? (filled($data['milestone_id']) ? (int) $data['milestone_id'] : null)
                : $locked->milestone_id;

            $this->ensureMilestoneBelongs($milestoneId, $locked->project_id);
            $this->ensureWithinAgreedAmount($seat, $amount, $locked);

            $locked->forceFill([
                'amount' => $amount,
                'milestone_id' => $milestoneId,
                'notes' => array_key_exists('notes', $data) ? (filled($data['notes']) ? trim((string) $data['notes']) : null) : $locked->notes,
            ])->save();

            activity('payout')->causedBy($actor)->performedOn($locked)->event('updated')
                ->withProperties(['project_id' => $locked->project_id, 'amount' => $amount])->log('Payout edited');

            return $locked;
        });
    }
}
