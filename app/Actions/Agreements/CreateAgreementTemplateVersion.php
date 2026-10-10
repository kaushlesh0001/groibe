<?php

namespace App\Actions\Agreements;

use App\Models\AgreementTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Editing a template never rewrites accepted text: it publishes the next version and retires the old one,
 * so every acceptance keeps pointing at the exact wording the person agreed to (spec 7.7, 8).
 */
class CreateAgreementTemplateVersion
{
    /** @param  array{title: string, body_html: string, effective_from?: mixed}  $data */
    public function __invoke(AgreementTemplate $current, User $actor, array $data, bool $activate = true): AgreementTemplate
    {
        Gate::forUser($actor)->authorize('update', $current);

        return DB::transaction(function () use ($current, $actor, $data, $activate): AgreementTemplate {
            $next = (int) AgreementTemplate::query()->where('type', $current->type)->lockForUpdate()->max('version') + 1;

            if ($activate) {
                AgreementTemplate::query()->where('type', $current->type)->update(['is_active' => false]);
            }

            $template = AgreementTemplate::create([
                'type' => $current->type,
                'version' => $next,
                'title' => $data['title'],
                'body_html' => $data['body_html'],
                'is_active' => $activate,
                'effective_from' => $data['effective_from'] ?? now(),
            ]);

            activity('agreement')
                ->causedBy($actor)
                ->performedOn($template)
                ->event('version_published')
                ->withProperties(['type' => $template->type->value, 'version' => $template->version, 'previous_version' => $current->version])
                ->log("Published {$template->type->value} v{$template->version}");

            return $template;
        });
    }
}
