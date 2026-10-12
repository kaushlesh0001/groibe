<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Services\Admin\DashboardMetrics;

class OrganizationsByStatusWidget extends BreakdownWidget
{
    protected static ?int $sort = 11;

    protected static array $permissions = ['organizations.view'];

    protected function heading(): string
    {
        return 'Organizations';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->organizationsByStatus();
    }

    protected function url(): ?string
    {
        return OrganizationResource::getUrl('index');
    }
}
