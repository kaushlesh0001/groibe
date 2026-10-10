<?php

namespace App\Filament\Admin\Resources\Applications\Pages;

use App\Filament\Admin\Resources\Applications\Actions\ApplicationActions;
use App\Filament\Admin\Resources\Applications\ApplicationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewApplication extends ViewRecord
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApplicationActions::shortlist(),
            ApplicationActions::offer(),
            ApplicationActions::reject(),
        ];
    }
}
