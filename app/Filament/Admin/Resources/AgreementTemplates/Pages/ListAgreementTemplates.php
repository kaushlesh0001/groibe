<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Pages;

use App\Filament\Admin\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgreementTemplates extends ListRecords
{
    protected static string $resource = AgreementTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New version')];
    }
}
