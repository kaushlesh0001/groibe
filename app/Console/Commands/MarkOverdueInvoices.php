<?php

namespace App\Console\Commands;

use App\Actions\Invoices\MarkInvoiceOverdue;
use App\Enums\InvoiceStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\InvoiceRef;
use App\Notifications\InvoicesOverdue;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Daily job (spec 17.1 scheduler, SCR-024): moves `sent` invoice references whose due date has passed to `overdue`, then sends
 * finance one digest of what changed. Running it twice in a day changes nothing the second time.
 */
class MarkOverdueInvoices extends Command
{
    protected $signature = 'groibe:mark-overdue-invoices';

    protected $description = 'Mark sent invoice references past their due date as overdue and tell finance';

    public function handle(MarkInvoiceOverdue $markOverdue, NotificationDispatcher $dispatcher): int
    {
        $changed = [];

        InvoiceRef::query()
            ->where('status', InvoiceStatus::Sent->value)
            ->whereDate('due_at', '<', today())
            ->with('project:id,code')
            ->orderBy('due_at')
            ->chunkById(100, function ($invoices) use ($markOverdue, &$changed): void {
                foreach ($invoices as $invoice) {
                    try {
                        $markOverdue($invoice);
                        $changed[] = ['project' => $invoice->project->code, 'ref' => $invoice->zoho_invoice_ref, 'due' => $invoice->due_at->toFormattedDateString()];
                    } catch (IllegalTransition|ActionBlocked) {
                        // Paid or changed by someone else a moment ago: nothing to do.
                    }
                }
            });

        if ($changed !== []) {
            $dispatcher->toAdmins('invoice_refs.update', new InvoicesOverdue($changed));
        }

        $this->info('Marked '.count($changed).' invoice '.(count($changed) === 1 ? 'reference' : 'references').' overdue.');

        return self::SUCCESS;
    }
}
