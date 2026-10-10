<?php

namespace App\Filament\Admin\Resources\OpenRoles\Pages;

use App\Filament\Admin\Resources\OpenRoles\OpenRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOpenRoles extends ListRecords
{
    protected static string $resource = OpenRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
