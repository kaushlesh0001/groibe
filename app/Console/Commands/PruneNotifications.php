<?php

namespace App\Console\Commands;

use App\Services\Ops\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily retention for in-app notifications (spec 17.4, SCR-039): a notification that has been read is deleted 180 days after it
 * was created (setting `retention.notifications_read_days`). An unread one is never deleted, however old. Every run writes
 * the number removed, even when none, to the audit log.
 */
class PruneNotifications extends Command
{
    protected $signature = 'groibe:prune-notifications';

    protected $description = 'Delete read notifications older than their retention period; unread ones stay';

    private const CHUNK = 1000;

    public function handle(Retention $retention): int
    {
        $days = $retention->days('notifications_read_days');
        $cutoff = now()->subDays($days);
        $removed = 0;

        // In slices of ids, so a large backlog never holds one long lock on a shared host.
        do {
            $ids = DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            $removed += $ids->isEmpty() ? 0 : DB::table('notifications')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        activity('notifications')->event('retention')
            ->withProperties(['removed' => $removed, 'days' => $days])
            ->log('Read notification retention run');

        $this->info("Removed {$removed} read notifications.");

        return self::SUCCESS;
    }
}
