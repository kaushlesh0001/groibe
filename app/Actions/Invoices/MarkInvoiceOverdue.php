<?php

namespace App\Actions\Invoices;

use App\Actions\Invoices\Concerns\MovesInvoice;
use App\Enums\InvoiceStatus;
use App\Exceptions\ActionBlocked;
use App\Models\InvoiceRef;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * sent -> overdue (SCR-024), once the due date has passed. The daily job runs it with no actor. A person may run it too,
 * but never before the due date has passed.
 */
class MarkInvoiceOverdue
{
    use MovesInvoice;

    public function __invoke(InvoiceRef $invoice, ?User $actor = null): InvoiceRef
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $invoice);
        }

        return $this->moveInvoice($invoice, InvoiceStatus::Overdue, $actor, guard: function (InvoiceRef $locked): void {
            if ($locked->due_at === null || ! $locked->due_at->isBefore(today())) {
                throw ActionBlocked::guardFailed('An invoice is only overdue after its due date has passed.');
            }
        });
    }
}
