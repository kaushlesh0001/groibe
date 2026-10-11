<?php

namespace App\Actions\Invoices;

use App\Actions\Invoices\Concerns\MovesInvoice;
use App\Enums\InvoiceStatus;
use App\Models\InvoiceRef;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** draft -> sent (SCR-024). Needs a due date; the issue date defaults to today. */
class SendInvoiceRef
{
    use MovesInvoice;

    public function __invoke(InvoiceRef $invoice, User $actor): InvoiceRef
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        return $this->moveInvoice(
            $invoice,
            InvoiceStatus::Sent,
            $actor,
            ['issued_at' => $invoice->issued_at ?? now()->toDateString()],
            fn (InvoiceRef $locked) => $this->requireDueDate($locked),
        );
    }
}
