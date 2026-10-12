<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Admin\DashboardMetrics;
use App\Services\Ops\CronHeartbeat;
use Filament\Widgets\Widget;

/**
 * Spec 17.4 "System status": queue backlog, failed jobs and the last cron run. It shows only to admins who
 * can read settings (super admin), because failed jobs and cron health are operator information.
 */
class SystemStatusWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.system-status';

    protected static ?int $sort = 30;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $heartbeat = app(CronHeartbeat::class);
        $queue = app(DashboardMetrics::class)->queueHealth();
        $lastRun = $heartbeat->lastRun();

        return [
            'cronHealthy' => $heartbeat->isHealthy(),
            'lastRun' => $lastRun?->diffForHumans(),
            'queue' => $queue,
        ];
    }
}
