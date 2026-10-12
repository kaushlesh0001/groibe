<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Applications\ApplicationResource;
use App\Services\Admin\DashboardMetrics;

class ApplicationsAwaitingWidget extends BreakdownWidget
{
    protected static ?int $sort = 20;

    protected static array $permissions = ['applications.view'];

    protected function heading(): string
    {
        return 'Applications awaiting action';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->applicationsAwaitingAction();
    }

    protected function url(): ?string
    {
        return ApplicationResource::getUrl('index');
    }
}
