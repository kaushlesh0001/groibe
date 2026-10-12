<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Support\ExportAction;
use App\Services\Exports\Definitions\ClientExport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Only client users are exported from here, whatever the list shows; admins are never exported.
            ExportAction::make(new ClientExport, fn () => $this->getFilteredTableQuery(), 'Export clients'),
            CreateAction::make(),
        ];
    }
}
