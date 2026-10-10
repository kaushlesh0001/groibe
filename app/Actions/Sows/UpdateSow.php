<?php

namespace App\Actions\Sows;

use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Models\AgreementTemplate;
use App\Models\Sow;
use App\Models\User;
use App\Services\Sows\SowRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 6.3 and SCR-007: a draft is edited in place; editing a sent SOW publishes a new draft version and
 * supersedes the old one (the project stays sow_sent); an accepted SOW is immutable.
 */
class UpdateSow
{
    public function __construct(private SowRenderer $renderer) {}

    /**
     * @param  array{title?: string, body_html?: string, total_value?: string|float|int|null, payment_terms?: string|null, rerender?: bool}  $data
     * @return Sow the draft that now carries the changes (the same row for a draft, a new version for a sent SOW)
     */
    public function __invoke(Sow $sow, User $actor, array $data): Sow
    {
        Gate::forUser($actor)->authorize('update', $sow);

        return DB::transaction(function () use ($sow, $actor, $data): Sow {
            $locked = Sow::query()->lockForUpdate()->findOrFail($sow->getKey());

            $result = match ($locked->status) {
                SowStatus::Draft => $this->updateDraft($locked, $data),
                SowStatus::Sent => $this->supersede($locked, $data),
                SowStatus::Accepted => throw ActionBlocked::immutable('An accepted SOW'),
                SowStatus::Rejected, SowStatus::Superseded => throw ActionBlocked::immutable('A '.$locked->status->value.' SOW'),
            };

            activity('sow')
                ->causedBy($actor)
                ->performedOn($result)
                ->event($locked->status === SowStatus::Draft && $result->is($locked) ? 'updated' : 'superseded')
                ->withProperties(['version' => $result->version, 'previous_version' => $locked->version])
                ->log("SOW v{$result->version} edited");

            return $result;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function updateDraft(Sow $sow, array $data): Sow
    {
        $sow->fill($this->changes($sow, $data))->save();

        return $sow;
    }

    /** @param  array<string, mixed>  $data */
    private function supersede(Sow $old, array $data): Sow
    {
        $old->loadMissing('project');

        $new = $old->project->sows()->create([
            'version' => (int) $old->project->sows()->max('version') + 1,
            'status' => SowStatus::Draft,
            'title' => $old->title,
            'body_html' => $old->body_html,
            'body_variables' => $old->body_variables,
            'total_value' => $old->total_value,
            'currency' => $old->currency,
            'payment_terms' => $old->payment_terms,
        ]);

        $new->fill($this->changes($new, $data))->save();
        $old->forceFill(['status' => SowStatus::Superseded, 'superseded_by' => $new->getKey()])->save();

        return $new;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changes(Sow $sow, array $data): array
    {
        $changes = array_intersect_key($data, array_flip(['title', 'body_html', 'total_value', 'payment_terms']));

        if (($data['rerender'] ?? false) === true) {
            $templateId = $sow->templateId();
            $template = $templateId !== null ? AgreementTemplate::query()->find($templateId) : null;

            if ($template === null) {
                throw ActionBlocked::missingTemplate('SOW template');
            }

            $sow->loadMissing('project');
            $rendered = $this->renderer->render($template, $sow->project, [
                'total_value' => $changes['total_value'] ?? $sow->total_value,
                'payment_terms' => $changes['payment_terms'] ?? $sow->payment_terms,
            ]);

            $changes['body_html'] = $rendered['html'];
            $changes['body_variables'] = [...$rendered['variables'], '_template_id' => $template->getKey(), '_template_version' => $template->version];
        }

        return $changes;
    }
}
