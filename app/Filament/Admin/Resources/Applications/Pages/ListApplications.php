<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Filament\Admin\Resources\Applications\ApplicationResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\ApplicationExport;
use Filament\Resources\Pages\ListRecords;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(new ApplicationExport, fn () => $this->getFilteredTableQuery())];
    }
}
