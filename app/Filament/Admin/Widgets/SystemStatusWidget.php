<?php

namespace App\Filament\Admin\Widgets;

use App\Models\AgreementTemplate;
use App\Models\DataRequest;
use App\Services\Admin\DashboardMetrics;
use App\Services\Ops\CronHeartbeat;
use App\Services\Ops\DatabaseSize;
use Filament\Widgets\Widget;

/**
 * Spec 17.4 "System status": queue backlog, failed jobs, the last cron run and the database size (SCR-034). It shows only to admins who
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
            // SCR-039: a heartbeat older than 10 minutes is an alert; "no run recorded yet" is not.
            'cronStoppedMinutes' => ! $heartbeat->isHealthy() ? $heartbeat->minutesSinceLastRun() : null,
            'dataRequestsWaiting' => DataRequest::query()->waitingLongerThan(max(1, (int) config('groibe.ops.data_request_reminder_days')))->count(),
            'dataRequestDays' => max(1, (int) config('groibe.ops.data_request_reminder_days')),
            'testWordingTemplates' => $this->testWordingTemplates(),
            'lastRun' => $lastRun?->diffForHumans(),
            'queue' => $queue,
            // The last daily measurement (SCR-034); the page never runs the size query itself.
            'database' => app(DatabaseSize::class)->lastCheck(),
        ];
    }

    /**
     * Titles of active agreement templates that still hold the test wording banner, on production only (SCR-039). On staging
     * the banner is expected, so nothing is flagged there.
     *
     * @return list<string>
     */
    private function testWordingTemplates(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        return AgreementTemplate::query()->active()->get()
            ->filter(fn (AgreementTemplate $template): bool => $template->isTestWording())
            ->map(fn (AgreementTemplate $template): string => $template->title.' (version '.$template->version.')')
            ->values()
            ->all();
    }
}
