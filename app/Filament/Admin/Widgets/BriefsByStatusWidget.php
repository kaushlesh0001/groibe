<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Services\Admin\DashboardMetrics;

class BriefsByStatusWidget extends BreakdownWidget
{
    protected static ?int $sort = 12;

    protected static array $permissions = ['briefs.view'];

    protected function heading(): string
    {
        return 'Briefs by status';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->briefsByStatus();
    }

    protected function url(): ?string
    {
        return BriefResource::getUrl('index');
    }
}
