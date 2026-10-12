<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Services\Admin\DashboardMetrics;

class ReviewsAwaitingModerationWidget extends BreakdownWidget
{
    protected static ?int $sort = 21;

    protected static array $permissions = ['reviews.view'];

    protected function heading(): string
    {
        return 'Reviews awaiting moderation';
    }

    protected function rows(DashboardMetrics $metrics): array
    {
        return ['Pending moderation' => $metrics->reviewsAwaitingModeration()];
    }

    protected function url(): ?string
    {
        return ReviewResource::getUrl('index');
    }
}
