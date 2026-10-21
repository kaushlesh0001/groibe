<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Pages;

use App\Actions\Agreements\PublishAgreementTemplateVersion;
use App\Enums\AgreementType;
use App\Filament\Admin\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateAgreementTemplate extends CreateRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    protected static ?string $title = 'Publish agreement version';

    /** Creating always means publishing the next version of the chosen type, never inserting a row by hand. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PublishAgreementTemplateVersion::class)(
                $data['type'] instanceof AgreementType ? $data['type'] : AgreementType::from($data['type']),
                auth()->user(),
                ['title' => $data['title'], 'body_html' => $data['body_html'], 'effective_from' => $data['effective_from']],
            );
        } catch (ValidationException $e) {
            // Show the refusal (SCR-039) on the body field of the form.
            throw ValidationException::withMessages(['data.body_html' => $e->errors()['body_html'] ?? $e->getMessage()]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'New version published';
    }
}
