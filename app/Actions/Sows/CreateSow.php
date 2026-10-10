<?php

namespace App\Actions\Sows;

use App\Enums\AgreementType;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementTemplate;
use App\Models\Project;
use App\Models\Sow;
use App\Models\User;
use App\Services\Sows\SowRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Creates the next draft SOW version for a project, rendered from the active SOW template (spec 8). */
class CreateSow
{
    public function __construct(private SowRenderer $renderer) {}

    /** @param  array{title?: string, total_value?: string|float|int|null, payment_terms?: string|null, scope?: string|null}  $data */
    public function __invoke(Project $project, User $actor, array $data = []): Sow
    {
        Gate::forUser($actor)->authorize('create', Sow::class);

        $template = AgreementTemplate::currentFor(AgreementType::SowTemplate)
            ?? throw ActionBlocked::missingTemplate('SOW template');

        return DB::transaction(function () use ($project, $actor, $data, $template): Sow {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            if (! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::SowSent], true)) {
                throw ActionBlocked::immutable('The SOW of a project past the proposal stage');
            }

            if ($locked->sows()->where('status', SowStatus::Accepted->value)->exists()) {
                throw ActionBlocked::immutable('An accepted SOW');
            }

            if ($locked->sows()->where('status', SowStatus::Draft->value)->exists()) {
                throw ActionBlocked::guardFailed('A draft SOW already exists for this project. Edit it instead.');
            }

            $rendered = $this->renderer->render($template, $locked, $data);

            $sow = $locked->sows()->create([
                'version' => (int) $locked->sows()->max('version') + 1,
                'status' => SowStatus::Draft,
                'title' => $data['title'] ?? 'Statement of Work: '.$locked->title,
                'body_html' => $rendered['html'],
                'body_variables' => [...$rendered['variables'], '_template_id' => $template->getKey(), '_template_version' => $template->version],
                'total_value' => $data['total_value'] ?? $locked->contract_value,
                'currency' => $locked->currency,
                'payment_terms' => $data['payment_terms'] ?? null,
            ]);

            activity('sow')
                ->causedBy($actor)
                ->performedOn($sow)
                ->event('created')
                ->withProperties(['project_id' => $locked->getKey(), 'version' => $sow->version])
                ->log("SOW v{$sow->version} drafted");

            return $sow;
        });
    }
}
