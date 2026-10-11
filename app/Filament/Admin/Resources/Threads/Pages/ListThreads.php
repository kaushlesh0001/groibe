<?php

namespace App\Filament\Admin\Resources\Threads\Pages;

use App\Filament\Admin\Resources\Threads\ThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListThreads extends ListRecords
{
    protected static string $resource = ThreadResource::class;
}
