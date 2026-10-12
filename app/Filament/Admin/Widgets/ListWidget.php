<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Admin\DashboardMetrics;

/** A dashboard card with a total and the few oldest items that need action (spec 13). */
abstract class ListWidget extends BreakdownWidget
{
    protected string $view = 'filament.admin.widgets.list';

    protected function rows(DashboardMetrics $metrics): array
    {
        return [];
    }

    protected function emptyText(): string
    {
        return 'Nothing waiting.';
    }

    /** @return array{total: int, items: list<array{title: string, meta: string}>} */
    abstract protected function summary(DashboardMetrics $metrics): array;

    protected function getViewData(): array
    {
        $summary = $this->summary(app(DashboardMetrics::class));

        return [
            'heading' => $this->heading(),
            'total' => $summary['total'],
            'items' => $summary['items'],
            'empty' => $this->emptyText(),
            'url' => $this->url(),
        ];
    }
}
