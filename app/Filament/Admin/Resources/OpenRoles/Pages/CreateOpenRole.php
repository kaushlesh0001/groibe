<?php

namespace App\Filament\Admin\Resources\OpenRoles\Pages;

use App\Actions\OpenRoles\SaveOpenRole;
use App\Filament\Admin\Resources\OpenRoles\OpenRoleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOpenRole extends CreateRecord
{
    protected static string $resource = OpenRoleResource::class;

    /** Always a draft. Publishing is its own step on the role page. */
    protected function handleRecordCreation(array $data): Model
    {
        $skillIds = array_map('intval', $data['skill_ids'] ?? []);
        unset($data['skill_ids']);

        return app(SaveOpenRole::class)(auth()->user(), $data, null, $skillIds);
    }
}
