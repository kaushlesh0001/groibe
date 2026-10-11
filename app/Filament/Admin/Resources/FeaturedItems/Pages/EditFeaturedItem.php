<?php

namespace App\Filament\Admin\Resources\FeaturedItems\Pages;

use App\Filament\Admin\Resources\FeaturedItems\FeaturedItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeaturedItem extends EditRecord
{
    protected static string $resource = FeaturedItemResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
