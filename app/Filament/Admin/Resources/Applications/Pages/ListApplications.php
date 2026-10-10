<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Filament\Admin\Resources\Applications\ApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;
}
