<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Services\Admin\DashboardMetrics;

class QaQueueWidget extends ListWidget
{
    protected static ?int $sort = 15;

    protected static array $permissions = ['submissions.view'];

    protected function heading(): string
    {
        return 'QA queue';
    }

    protected function emptyText(): string
    {
        return 'No submissions are waiting for review.';
    }

    protected function summary(DashboardMetrics $metrics): array
    {
        $queue = $metrics->qaQueue();

        return [
            'total' => $queue['total'],
            'items' => array_map(fn (array $row): array => [
                'title' => $row['title'],
                'meta' => $row['project'].' · '.$row['who'].' · '.$row['since'],
            ], $queue['items']),
        ];
    }

    protected function url(): ?string
    {
        return SubmissionResource::getUrl('index');
    }
}
