<?php

namespace App\Actions\Invoices\Concerns;

use App\Enums\InvoiceStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\InvoiceRef;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Shared mechanics for the invoice transitions (spec 7.6, SCR-024): lock, validate the move, persist, log. Callers authorize first. */
trait MovesInvoice
{
    /**
     * @param  array<string, mixed>  $attributes  columns to write with the status change
     * @param  \Closure(InvoiceRef): void|null  $guard  extra checks on the locked row
     */
    protected function moveInvoice(InvoiceRef $invoice, InvoiceStatus $to, ?User $actor, array $attributes = [], ?\Closure $guard = null): InvoiceRef
    {
        DB::transaction(function () use ($invoice, $to, $actor, $attributes, $guard): void {
            $locked = InvoiceRef::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw IllegalTransition::for('invoice', $from->value, $to->value);
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill(['status' => $to, ...$attributes])->save();

            activity('invoice')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'project_id' => $locked->project_id])
                ->log("Invoice moved from {$from->value} to {$to->value}");
        });

        return $invoice->refresh();
    }

    protected function requireDueDate(InvoiceRef $locked): void
    {
        if ($locked->due_at === null) {
            throw ActionBlocked::guardFailed('Set a due date before marking the invoice as sent.');
        }
    }
}
