<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Services\Admin\DashboardMetrics;

class ContributorsByStateWidget extends BreakdownWidget
{
    protected static ?int $sort = 10;

    protected static array $permissions = ['contributors.view'];

    protected function heading(): string
    {
        return 'Contributors by state';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->contributorsByState();
    }

    protected function url(): ?string
    {
        return ContributorResource::getUrl('index');
    }
}
