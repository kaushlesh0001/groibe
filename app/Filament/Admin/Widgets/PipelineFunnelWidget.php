<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Admin\DashboardMetrics;

class PipelineFunnelWidget extends BreakdownWidget
{
    protected static ?int $sort = 14;

    protected static array $permissions = ['briefs.view', 'projects.view'];

    protected function heading(): string
    {
        return 'Pipeline: brief to closed project';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return $metrics->pipelineFunnel();
    }
}
