<?php

namespace App\Actions\Sows;

use App\Actions\Agreements\AcceptAgreement;
use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\AgreementType;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\AgreementTemplate;
use App\Models\Project;
use App\Models\Sow;
use App\Models\User;
use App\Notifications\SowAccepted;
use App\Services\Admin\Impersonation;
use App\Services\Agreements\AgreementGate;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * sent -> accepted, project sow_sent -> sow_accepted. Explicit confirmation is required (spec 8) and the
 * acceptance is stored on the SOW and in agreement_acceptances (user, org, version, time, ip, user agent).
 */
class AcceptSow
{
    use TransitionsProject;

    public function __construct(
        private AgreementGate $gate,
        private AcceptAgreement $recordAcceptance,
        private NotificationDispatcher $dispatcher,
    ) {}

    public function __invoke(Sow $sow, User $actor, bool $confirmed, ?string $ip = null, ?string $userAgent = null): Sow
    {
        Gate::forUser($actor)->authorize('accept', $sow);
        app(Impersonation::class)->assertNotImpersonating();

        if (! $confirmed) {
            throw ActionBlocked::guardFailed('Please confirm that you accept this Statement of Work.');
        }

        DB::transaction(function () use ($sow, $actor, $ip, $userAgent): void {
            $locked = Sow::query()->lockForUpdate()->findOrFail($sow->getKey());

            if (! $locked->status->canTransitionTo(SowStatus::Accepted)) {
                throw IllegalTransition::for('sow', $locked->status->value, SowStatus::Accepted->value);
            }

            $project = Project::query()->lockForUpdate()->with('organization')->findOrFail($locked->project_id);

            $this->gate->assertClientMsaAccepted($project->organization);

            if ($project->status !== ProjectStatus::SowSent) {
                throw ActionBlocked::guardFailed('This project is not waiting for a SOW acceptance.');
            }

            $templateId = $locked->templateId();
            $template = ($templateId !== null ? AgreementTemplate::query()->find($templateId) : null)
                ?? AgreementTemplate::currentFor(AgreementType::SowTemplate)
                ?? throw ActionBlocked::missingTemplate('SOW template');

            $locked->forceFill([
                'status' => SowStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by' => $actor->getKey(),
                'accepted_ip' => $ip,
                'accepted_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            ])->save();

            ($this->recordAcceptance)($template, $actor, $project->organization, $project, $ip, $userAgent);

            $this->moveProject($project, ProjectStatus::SowAccepted, $actor, null, properties: ['sow_version' => $locked->version]);

            activity('sow')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('accepted')
                ->withProperties(['version' => $locked->version, 'project_id' => $project->getKey(), 'organization_id' => $project->organization_id])
                ->log("SOW v{$locked->version} accepted");
        });

        $sow->refresh()->loadMissing('project.organization', 'project.deliveryManager');

        $notification = new SowAccepted($sow, $sow->project, $actor->name);
        $this->dispatcher->toAdmins('projects.update', $notification, $sow->project->deliveryManager);
        $this->dispatcher->toOrganization($sow->project->organization, $notification, $actor);

        return $sow;
    }
}
