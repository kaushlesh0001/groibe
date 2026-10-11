<?php

namespace App\Filament\Admin\Resources\HomepageSections\Pages;

use App\Filament\Admin\Resources\HomepageSections\HomepageSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHomepageSections extends ListRecords
{
    protected static string $resource = HomepageSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
