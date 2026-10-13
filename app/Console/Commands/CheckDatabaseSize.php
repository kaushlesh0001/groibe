<?php

namespace App\Console\Commands;

use App\Notifications\DatabaseSizeWarning;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Ops\DatabaseSize;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily housekeeping (spec 17.4, SCR-034). Measures the database, writes the size to the log, keeps it for the System
 * status widget, and warns super admins (in-app and email, once a day) while it is at 80% or more of `DB_SIZE_LIMIT_MB`.
 */
class CheckDatabaseSize extends Command
{
    protected $signature = 'groibe:check-database-size';

    protected $description = 'Record the database size and warn super admins when it nears the host limit';

    public function handle(DatabaseSize $size, NotificationDispatcher $dispatcher): int
    {
        $megabytes = $size->megabytes();
        $percent = $size->percentOf($megabytes);

        $size->record($megabytes);

        Log::info('Database size checked.', ['mb' => $megabytes, 'limit_mb' => $size->limitMb(), 'percent' => $percent]);

        $this->info(number_format($megabytes, 1)." MB of {$size->limitMb()} MB ({$percent}%).");

        if (! $size->isOverWarningLevel($megabytes)) {
            return self::SUCCESS;
        }

        if (Cache::add('groibe.housekeeping.db-size-warning.'.today()->toDateString(), true, now()->endOfDay())) {
            $dispatcher->toAdmins('settings.view', new DatabaseSizeWarning($megabytes, $size->limitMb(), $percent));
            $this->warn('Over the warning level: super admins were told.');
        }

        return self::SUCCESS;
    }
}
