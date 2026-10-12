<?php

namespace App\Filament\Admin\Resources\Organizations\Pages;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\OrganizationExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make(new OrganizationExport, fn () => $this->getFilteredTableQuery()),
            CreateAction::make(),
        ];
    }
}
