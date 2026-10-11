<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Exceptions\ActionBlocked;
use App\Models\InvoiceRef;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Creates or edits an invoice reference (spec 7.6, SCR-024). Always starts as a draft. A paid invoice is final. The Zoho
 * invoice number must be unique within the project.
 */
class SaveInvoiceRef
{
    /** @param  array{zoho_invoice_ref: string, amount: mixed, issued_at?: mixed, due_at?: mixed}  $data */
    public function __invoke(User $actor, Project $project, array $data, ?InvoiceRef $invoice = null): InvoiceRef
    {
        $gate = Gate::forUser($actor);
        $invoice === null ? $gate->authorize('create', InvoiceRef::class) : $gate->authorize('update', $invoice);

        if ($invoice !== null && $invoice->status === InvoiceStatus::Paid) {
            throw ActionBlocked::guardFailed('A paid invoice cannot be changed.');
        }

        $reference = trim($data['zoho_invoice_ref']);

        if ($reference === '' || mb_strlen($reference) > 100) {
            throw ActionBlocked::guardFailed('Enter the Zoho invoice number (up to 100 characters).');
        }

        $issued = $this->date($data['issued_at'] ?? null, 'issue date');
        $due = $this->date($data['due_at'] ?? null, 'due date');

        if ($issued !== null && $due !== null && $due->lt($issued)) {
            throw ActionBlocked::guardFailed('The due date cannot be before the issue date.');
        }

        $attributes = [
            'project_id' => $project->getKey(),
            'zoho_invoice_ref' => $reference,
            'amount' => Money::positive($data['amount'] ?? null, 'The invoice amount'),
            'issued_at' => $issued?->toDateString(),
            'due_at' => $due?->toDateString(),
        ];

        try {
            if ($invoice === null) {
                $invoice = InvoiceRef::query()->create([...$attributes, 'currency' => 'INR', 'status' => InvoiceStatus::Draft]);
                $event = 'created';
            } else {
                $invoice->forceFill($attributes)->save();
                $event = 'updated';
            }
        } catch (UniqueConstraintViolationException) {
            throw ActionBlocked::guardFailed('This project already has an invoice with that Zoho invoice number.');
        }

        activity('invoice')->causedBy($actor)->performedOn($invoice)->event($event)
            ->withProperties(['project_id' => $project->getKey(), 'amount' => $attributes['amount']])->log("Invoice reference {$event}");

        return $invoice;
    }

    private function date(mixed $value, string $label): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            throw ActionBlocked::guardFailed("Enter a valid {$label}.");
        }
    }

    /** True when every invoice reference of the project adds up to more than its contract value. Used to warn, never to block (SCR-024). */
    public static function exceedsContractValue(Project $project): bool
    {
        if ($project->contract_value === null) {
            return false;
        }

        $total = (string) InvoiceRef::query()->where('project_id', $project->getKey())->sum('amount');

        return bccomp($total, (string) $project->contract_value, 2) > 0;
    }
}
