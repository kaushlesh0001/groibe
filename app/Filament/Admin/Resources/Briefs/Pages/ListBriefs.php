<?php

namespace App\Filament\Admin\Resources\Briefs\Pages;

use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\BriefExport;
use Filament\Resources\Pages\ListRecords;

class ListBriefs extends ListRecords
{
    protected static string $resource = BriefResource::class;

    protected function getHeaderActions(): array
    {
        return [ExportAction::make(new BriefExport, fn () => $this->getFilteredTableQuery())];
    }
}
