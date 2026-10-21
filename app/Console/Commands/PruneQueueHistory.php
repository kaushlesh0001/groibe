<?php

namespace App\Console\Commands;

use App\Services\Ops\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily retention for the queue's own tables (spec 17.4, SCR-039): `failed_jobs` by `failed_at` and `job_batches` by
 * `created_at`, 30 days by default (settings `retention.failed_jobs_days` and `retention.job_batches_days`). Every run
 * writes the numbers removed, even when none, to the audit log.
 */
class PruneQueueHistory extends Command
{
    protected $signature = 'groibe:prune-queue-history';

    protected $description = 'Delete failed jobs and job batches older than their retention period';

    public function handle(Retention $retention): int
    {
        $failedDays = $retention->days('failed_jobs_days');
        $batchDays = $retention->days('job_batches_days');

        $failed = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($failedDays))->delete();
        // `job_batches.created_at` is a Unix timestamp, not a datetime.
        $batches = DB::table('job_batches')->where('created_at', '<', now()->subDays($batchDays)->getTimestamp())->delete();

        activity('queue')->event('retention')
            ->withProperties(['failed_jobs_removed' => $failed, 'job_batches_removed' => $batches, 'failed_jobs_days' => $failedDays, 'job_batches_days' => $batchDays])
            ->log('Queue history retention run');

        $this->info("Removed {$failed} failed jobs and {$batches} job batches.");

        return self::SUCCESS;
    }
}
