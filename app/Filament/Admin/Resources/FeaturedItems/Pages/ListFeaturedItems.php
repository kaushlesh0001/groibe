<?php

namespace App\Filament\Admin\Resources\FeaturedItems\Pages;

use App\Filament\Admin\Resources\FeaturedItems\FeaturedItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeaturedItems extends ListRecords
{
    protected static string $resource = FeaturedItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
