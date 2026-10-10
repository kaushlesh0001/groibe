<?php

namespace App\Filament\Admin\Resources\FeatureFlags\Pages;

use App\Filament\Admin\Resources\FeatureFlags\FeatureFlagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeatureFlag extends CreateRecord
{
    protected static string $resource = FeatureFlagResource::class;
}
