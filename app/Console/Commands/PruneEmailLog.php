<?php

namespace App\Console\Commands;

use App\Enums\EmailStatus;
use App\Models\EmailLog;
use Illuminate\Console\Command;

/**
 * Daily retention for the email log (spec 14, 17.1, SCR-032): `sent` rows older than 90 days and `failed` rows older than
 * 180 days are deleted, judged by `created_at`. It is the only thing that deletes email log rows. Every run writes the
 * number removed, even when it is none, to the audit log.
 */
class PruneEmailLog extends Command
{
    protected $signature = 'groibe:prune-email-log';

    protected $description = 'Delete sent email log rows after 90 days and failed ones after 180 days';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $sentDays = (int) config('groibe.ops.email_log_sent_days');
        $failedDays = (int) config('groibe.ops.email_log_failed_days');

        $sent = $this->prune(EmailStatus::Sent, $sentDays);
        $failed = $this->prune(EmailStatus::Failed, $failedDays);

        activity('email_log')->event('retention')
            ->withProperties(['sent_removed' => $sent, 'failed_removed' => $failed, 'sent_days' => $sentDays, 'failed_days' => $failedDays])
            ->log('Email log retention run');

        $this->info("Removed {$sent} sent and {$failed} failed email log rows.");

        return self::SUCCESS;
    }

    /** Deletes in slices of ids, so a large backlog never holds one long lock on a shared host. */
    private function prune(EmailStatus $status, int $days): int
    {
        $cutoff = now()->subDays($days);
        $removed = 0;

        do {
            $ids = EmailLog::query()
                ->where('status', $status->value)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            $removed += $ids->isEmpty() ? 0 : EmailLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $removed;
    }
}
