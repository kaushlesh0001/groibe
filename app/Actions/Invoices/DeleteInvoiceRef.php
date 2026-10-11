<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Exceptions\ActionBlocked;
use App\Models\InvoiceRef;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Deletes an invoice reference that is still a draft. Sent, overdue and paid ones stay as evidence (SCR-024). */
class DeleteInvoiceRef
{
    public function __invoke(InvoiceRef $invoice, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $invoice);

        DB::transaction(function () use ($invoice, $actor): void {
            $locked = InvoiceRef::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($locked->status !== InvoiceStatus::Draft) {
                throw ActionBlocked::guardFailed('Only a draft invoice reference can be deleted.');
            }

            activity('invoice')->causedBy($actor)->performedOn($locked)->event('deleted')
                ->withProperties(['project_id' => $locked->project_id])->log('Draft invoice reference deleted');

            $locked->delete();
        });
    }
}
