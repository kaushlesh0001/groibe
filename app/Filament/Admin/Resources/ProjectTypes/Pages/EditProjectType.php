<?php

namespace App\Filament\Admin\Resources\ProjectTypes\Pages;

use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectType extends EditRecord
{
    protected static string $resource = ProjectTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
