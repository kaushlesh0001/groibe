<?php

namespace App\Actions\Payouts;

use App\Actions\Payouts\Concerns\GuardsPayoutAmounts;
use App\Enums\AssignmentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Payout;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Plans a payout for a seat (spec 7.6, SCR-024). The seat must be active or completed, have an agreed amount, and sit on a
 * project that is not a draft. The amount defaults to the agreed amount. Fixed fee seats are capped at the agreed total.
 */
class CreatePayout
{
    use GuardsPayoutAmounts;

    /** @param  array{amount?: mixed, milestone_id?: int|string|null, notes?: string|null}  $data */
    public function __invoke(Assignment $assignment, User $actor, array $data = []): Payout
    {
        Gate::forUser($actor)->authorize('create', Payout::class);

        return DB::transaction(function () use ($assignment, $actor, $data): Payout {
            // The seat row is the lock that keeps two simultaneous payouts from both fitting under the cap.
            $seat = Assignment::query()->lockForUpdate()->with('project:id,status')->findOrFail($assignment->getKey());

            if (! in_array($seat->status, [AssignmentStatus::Active, AssignmentStatus::Completed], true)) {
                throw ActionBlocked::guardFailed('Payouts can only be planned for a contributor whose seat is active or completed.');
            }

            if ($seat->project->status === ProjectStatus::Draft) {
                throw ActionBlocked::guardFailed('Payouts cannot be planned while the project is a draft.');
            }

            if ($seat->agreed_amount === null) {
                throw ActionBlocked::guardFailed('Set the agreed amount for this seat on the project Team tab first.');
            }

            $amount = Money::positive($data['amount'] ?? $seat->agreed_amount);
            $milestoneId = filled($data['milestone_id'] ?? null) ? (int) $data['milestone_id'] : null;

            $this->ensureMilestoneBelongs($milestoneId, $seat->project_id);
            $this->ensureWithinAgreedAmount($seat, $amount);

            $payout = Payout::query()->create([
                'assignment_id' => $seat->getKey(),
                'user_id' => $seat->user_id,
                'project_id' => $seat->project_id,
                'milestone_id' => $milestoneId,
                'amount' => $amount,
                'currency' => $seat->currency,
                'status' => PayoutStatus::Planned,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'created_by' => $actor->getKey(),
            ]);

            activity('payout')->causedBy($actor)->performedOn($payout)->event('planned')
                ->withProperties(['project_id' => $payout->project_id, 'amount' => $amount])->log('Payout planned');

            return $payout;
        });
    }
}
