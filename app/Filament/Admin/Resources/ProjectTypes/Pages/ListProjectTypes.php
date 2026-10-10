<?php

namespace App\Filament\Admin\Resources\ProjectTypes\Pages;

use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectTypes extends ListRecords
{
    protected static string $resource = ProjectTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
