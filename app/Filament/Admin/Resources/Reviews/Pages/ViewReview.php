<?php

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Filament\Admin\Resources\Reviews\Actions\ReviewActions;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReview extends ViewRecord
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [ReviewActions::publish(), ReviewActions::hide()];
    }
}
