<?php

namespace App\Filament\Admin\Resources\Projects\Pages;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\ProjectExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make(new ProjectExport, fn () => $this->getFilteredTableQuery()),
            CreateAction::make(),
        ];
    }
}
