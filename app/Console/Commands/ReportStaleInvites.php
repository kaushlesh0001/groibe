<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\StaleInvitesReport;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily housekeeping (spec 17.4, SCR-034). Tells delivery managers, once, about people an admin or an import created who
 * still have no password after 14 days, so they can resend the invite. It never deletes or changes a person. The same run
 * clears expired password-reset tokens with Laravel's own command.
 */
class ReportStaleInvites extends Command
{
    protected $signature = 'groibe:report-stale-invites';

    protected $description = 'Report people invited but not activated after 14 days, and clear expired password-reset tokens';

    /** Names shown in the notice; the count is always the real total. */
    public const LIST_LIMIT = 5;

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $this->callSilently('auth:clear-resets');

        $days = max(1, (int) config('groibe.ops.stale_invite_days'));

        $stale = User::query()
            ->whereNull('password')
            ->where('status', UserStatus::Pending->value)
            ->where('role', '!=', UserRole::Admin->value)
            ->where('created_at', '<', now()->subDays($days));

        $total = (clone $stale)->count();

        if ($total === 0) {
            $this->info('No stale invites.');

            return self::SUCCESS;
        }

        // One notice a day, even if the command is run again by hand.
        if (! Cache::add('groibe.housekeeping.stale-invites.'.today()->toDateString(), true, now()->endOfDay())) {
            $this->info("{$total} stale invites, already reported today.");

            return self::SUCCESS;
        }

        $names = $stale->orderBy('created_at')->orderBy('id')->limit(self::LIST_LIMIT)->pluck('name')->all();

        $dispatcher->toAdmins('clients.update', new StaleInvitesReport($total, $names, $days));

        $this->info("Reported {$total} stale ".($total === 1 ? 'invite' : 'invites').' to delivery managers.');

        return self::SUCCESS;
    }
}
