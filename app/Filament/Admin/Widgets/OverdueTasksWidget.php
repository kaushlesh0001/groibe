<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Admin\DashboardMetrics;

class OverdueTasksWidget extends ListWidget
{
    protected static ?int $sort = 16;

    protected static array $permissions = ['tasks.view'];

    protected function heading(): string
    {
        return 'Overdue tasks';
    }

    protected function emptyText(): string
    {
        return 'No tasks are past their due date.';
    }

    protected function summary(DashboardMetrics $metrics): array
    {
        $overdue = $metrics->overdueTasks();

        return [
            'total' => $overdue['total'],
            'items' => array_map(fn (array $row): array => [
                'title' => $row['title'],
                'meta' => $row['project'].' · due '.$row['due'],
            ], $overdue['items']),
        ];
    }
}
