<?php

namespace App\Filament\Admin\Resources\FeatureFlags\Pages;

use App\Filament\Admin\Resources\FeatureFlags\FeatureFlagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeatureFlag extends EditRecord
{
    protected static string $resource = FeatureFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
