<?php

namespace App\Filament\Admin\Resources\Projects\Pages;

use App\Actions\Projects\CreateProject as CreateProjectAction;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /** The Action owns code generation, the draft status, skills and the activity entry. */
    protected function handleRecordCreation(array $data): Model
    {
        $skillIds = array_map('intval', $data['skills'] ?? []);
        unset($data['skills']);

        return app(CreateProjectAction::class)($data, auth()->user(), $skillIds);
    }
}
