<?php

namespace App\Actions\Agreements;

use App\Models\AgreementTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Revises an existing template: publishes the next version of its type and retires this one. */
class CreateAgreementTemplateVersion
{
    /** @param  array{title: string, body_html: string, effective_from?: mixed}  $data */
    public function __invoke(AgreementTemplate $current, User $actor, array $data): AgreementTemplate
    {
        Gate::forUser($actor)->authorize('update', $current);

        return app(PublishAgreementTemplateVersion::class)($current->type, $actor, $data);
    }
}
