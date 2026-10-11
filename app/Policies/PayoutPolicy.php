<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Payouts (spec 5 "Payout", SCR-024): finance_ops and super_admin create, change and delete; delivery_manager reads; an active
 * contributor reads only their own payouts once finance has approved them. Clients and community managers have no access.
 * Approving, paying and cancelling are updates. Exports need the separate `import_export.payouts` permission.
 */
class PayoutPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isActiveContributor() || $this->adminCan($actor, 'payouts.view');
    }

    public function view(User $actor, Payout $payout): bool
    {
        if ($this->adminCan($actor, 'payouts.view')) {
            return true;
        }

        return $actor->isActiveContributor()
            && $payout->user_id === $actor->getKey()
            && $payout->approved_at !== null;
    }

    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'payouts.create');
    }

    public function update(User $actor, Payout $payout): bool
    {
        return $this->adminCan($actor, 'payouts.update');
    }

    public function approve(User $actor, Payout $payout): bool
    {
        return $this->update($actor, $payout);
    }

    public function markPaid(User $actor, Payout $payout): bool
    {
        return $this->update($actor, $payout);
    }

    public function cancel(User $actor, Payout $payout): bool
    {
        return $this->update($actor, $payout);
    }

    public function delete(User $actor, Payout $payout): bool
    {
        return $this->adminCan($actor, 'payouts.delete');
    }

    public function deleteAny(User $actor): bool
    {
        return $this->adminCan($actor, 'payouts.delete');
    }

    public function export(User $actor): bool
    {
        return $this->adminCan($actor, 'import_export.payouts');
    }
}
