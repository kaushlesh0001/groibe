<?php

namespace App\Filament\Admin\Resources\Reviews\Pages;

use App\Filament\Admin\Resources\Reviews\Actions\ReviewActions;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\ReviewExport;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make(new ReviewExport, fn () => $this->getFilteredTableQuery()),
            ReviewActions::addInternal(),
        ];
    }
}
