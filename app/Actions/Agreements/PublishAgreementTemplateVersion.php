<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementType;
use App\Models\AgreementTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Publishes the next version of an agreement type, or version 1 when the type has none yet. A saved version is
 * never rewritten: the previous one is retired and stays on record so every acceptance keeps its exact wording
 * (spec 7.7, 8).
 */
class PublishAgreementTemplateVersion
{
    /** @param  array{title: string, body_html: string, effective_from?: mixed}  $data */
    public function __invoke(AgreementType $type, User $actor, array $data): AgreementTemplate
    {
        Gate::forUser($actor)->authorize('create', AgreementTemplate::class);

        // SCR-039: staging test wording must never become live wording on production. Nothing is saved.
        if (app()->environment('production') && AgreementTemplate::bodyHasTestWording($data['body_html'])) {
            throw ValidationException::withMessages([
                'body_html' => 'This text is marked as test wording ('.AgreementTemplate::TEST_WORDING_BANNER.') and cannot be published on the live site. Use the approved legal wording.',
            ]);
        }

        return DB::transaction(function () use ($type, $actor, $data): AgreementTemplate {
            $previous = AgreementTemplate::query()->where('type', $type)->lockForUpdate()->max('version');
            $next = (int) $previous + 1;

            AgreementTemplate::query()->where('type', $type)->update(['is_active' => false]);

            $template = AgreementTemplate::create([
                'type' => $type,
                'version' => $next,
                'title' => $data['title'],
                'body_html' => $data['body_html'],
                'is_active' => true,
                'effective_from' => $data['effective_from'] ?? now(),
            ]);

            activity('agreement')
                ->causedBy($actor)
                ->performedOn($template)
                ->event('version_published')
                ->withProperties(['type' => $type->value, 'version' => $next, 'previous_version' => $previous])
                ->log("Published {$type->value} v{$next}");

            return $template;
        });
    }
}
