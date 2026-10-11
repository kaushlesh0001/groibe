<?php

namespace App\Policies;

use App\Models\User;

/**
 * Invoice references (SCR-024): finance_ops and super_admin full control, delivery_manager read-only. Clients and
 * contributors have no access, since the invoice itself lives in Zoho Books. Exports need `import_export.payouts`.
 */
class InvoiceRefPolicy extends PermissionPolicy
{
    protected function resource(): string
    {
        return 'invoice_refs';
    }

    public function export(User $actor): bool
    {
        return $actor->isAdmin() && $actor->can('import_export.payouts');
    }
}
