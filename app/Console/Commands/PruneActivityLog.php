<?php

namespace App\Console\Commands;

use App\Services\Ops\Retention;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Daily retention for the audit trail (spec 17.4, SCR-039): entries older than 365 days are removed (setting
 * `retention.activity_log_days`) with Spatie's own `activitylog:clean`. The entry that records the run is written after the
 * clean, so it is kept.
 */
class PruneActivityLog extends Command
{
    protected $signature = 'groibe:prune-activity-log';

    protected $description = 'Delete audit log entries older than their retention period';

    public function handle(Retention $retention): int
    {
        $days = $retention->days('activity_log_days');
        $before = Activity::query()->count();

        $this->callSilently('activitylog:clean', ['--days' => $days, '--force' => true]);

        $removed = max(0, $before - Activity::query()->count());

        activity('audit_log')->event('retention')
            ->withProperties(['removed' => $removed, 'days' => $days])
            ->log('Audit log retention run');

        $this->info("Removed {$removed} audit log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
