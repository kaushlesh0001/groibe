<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Services\Admin\DashboardMetrics;

class ProjectsByStatusWidget extends BreakdownWidget
{
    protected static ?int $sort = 13;

    protected static array $permissions = ['projects.view'];

    protected function heading(): string
    {
        return 'Projects by status';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->projectsByStatus();
    }

    protected function url(): ?string
    {
        return ProjectResource::getUrl('index');
    }
}
