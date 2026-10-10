<?php

namespace App\Filament\Admin\Resources\Briefs\Pages\Concerns;

use App\Models\Brief;

/** Related names shown read-only on the brief form are filled here, so the form never lazy loads them. */
trait FillsBriefDisplayData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Brief $brief */
        $brief = $this->getRecord();
        $brief->loadMissing(['organization:id,name', 'projectType:id,name']);

        $data['organization_name'] = $brief->organization->name;
        $data['project_type_name'] = $brief->projectType?->name;

        return $data;
    }
}
