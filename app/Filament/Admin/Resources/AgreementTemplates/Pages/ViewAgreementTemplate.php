<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Pages;

use App\Filament\Admin\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAgreementTemplate extends ViewRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Revise')];
    }
}
