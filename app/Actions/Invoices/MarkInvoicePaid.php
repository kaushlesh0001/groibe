<?php

namespace App\Actions\Invoices;

use App\Actions\Invoices\Concerns\MovesInvoice;
use App\Enums\InvoiceStatus;
use App\Models\InvoiceRef;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** sent or overdue -> paid (SCR-024). Payment itself is recorded in Zoho Books; this only mirrors the status. */
class MarkInvoicePaid
{
    use MovesInvoice;

    public function __invoke(InvoiceRef $invoice, User $actor): InvoiceRef
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return $this->moveInvoice($invoice, InvoiceStatus::Paid, $actor);
    }
}
