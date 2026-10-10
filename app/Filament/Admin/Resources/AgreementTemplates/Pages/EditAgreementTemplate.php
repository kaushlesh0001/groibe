<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Pages;

use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Filament\Admin\Resources\AgreementTemplates\AgreementTemplateResource;
use App\Models\AgreementTemplate;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAgreementTemplate extends EditRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    /** Saving never rewrites accepted text: it publishes the next version and moves this page to it. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof AgreementTemplate) {
            abort(404);
        }

        $new = app(CreateAgreementTemplateVersion::class)($record, auth()->user(), [
            'title' => $data['title'],
            'body_html' => $data['body_html'],
        ]);

        $this->record = $new;

        return $new;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'New version published';
    }
}
