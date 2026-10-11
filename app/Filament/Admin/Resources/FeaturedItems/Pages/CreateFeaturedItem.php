<?php

namespace App\Filament\Admin\Resources\FeaturedItems\Pages;

use App\Filament\Admin\Resources\FeaturedItems\FeaturedItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeaturedItem extends CreateRecord
{
    protected static string $resource = FeaturedItemResource::class;
}
