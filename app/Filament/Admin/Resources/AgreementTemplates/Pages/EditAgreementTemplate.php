<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Pages;

use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Filament\Admin\Resources\AgreementTemplates\AgreementTemplateResource;
use App\Models\AgreementTemplate;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditAgreementTemplate extends EditRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    /** The revision starts today, whatever date the version being revised carried. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['effective_from'] = now();

        return $data;
    }

    /** Saving never rewrites accepted text: it publishes the next version and moves this page to it. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof AgreementTemplate) {
            abort(404);
        }

        try {
            $new = app(CreateAgreementTemplateVersion::class)($record, auth()->user(), [
                'title' => $data['title'],
                'body_html' => $data['body_html'],
                'effective_from' => $data['effective_from'],
            ]);
        } catch (ValidationException $e) {
            // Show the refusal (SCR-039) on the body field of the form.
            throw ValidationException::withMessages(['data.body_html' => $e->errors()['body_html'] ?? $e->getMessage()]);
        }

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
