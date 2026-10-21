<?php

namespace App\Console\Commands;

use App\Models\DataRequest;
use App\Notifications\DataRequestsWaiting;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily reminder (spec 17.4, SCR-039). Super admins get one in-app and email notice a day while any data request that is
 * still open or in progress was made more than `groibe.ops.data_request_reminder_days` (7) days ago. Nothing is sent when
 * none is waiting. The notice carries a count and an age, never a name.
 */
class RemindDataRequests extends Command
{
    protected $signature = 'groibe:remind-data-requests';

    protected $description = 'Remind super admins of data requests that have waited more than 7 days';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $days = max(1, (int) config('groibe.ops.data_request_reminder_days'));

        $waiting = DataRequest::query()->waitingLongerThan($days);
        $total = (clone $waiting)->count();

        if ($total === 0) {
            $this->info('No data request has waited more than '.$days.' days.');

            return self::SUCCESS;
        }

        // One notice a day, even if the command is run again by hand.
        if (! Cache::add('groibe.housekeeping.data-request-reminder.'.today()->toDateString(), true, now()->endOfDay())) {
            $this->info("{$total} waiting, already reminded today.");

            return self::SUCCESS;
        }

        $oldest = (clone $waiting)->min('created_at');
        $oldestDays = $oldest === null ? $days : (int) now()->diffInDays($oldest, true);

        $dispatcher->toAdmins('data_requests.update', new DataRequestsWaiting($total, $oldestDays, $days));

        $this->info("Reminded super admins of {$total} waiting ".($total === 1 ? 'request' : 'requests').'.');

        return self::SUCCESS;
    }
}
