<?php

namespace App\Filament\Admin\Resources\Organizations\Pages;

use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
