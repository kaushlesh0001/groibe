<?php

namespace App\Filament\Admin\Resources\FeatureFlags\Pages;

use App\Filament\Admin\Resources\FeatureFlags\FeatureFlagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeatureFlags extends ListRecords
{
    protected static string $resource = FeatureFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
