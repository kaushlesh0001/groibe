<?php

namespace App\Actions\Sows;

use App\Actions\Messages\GetProjectThread;
use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Project;
use App\Models\Sow;
use App\Models\User;
use App\Notifications\SowSent;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** draft -> sent. Moves a draft project to sow_sent; a project already at sow_sent stays there (SCR-007). */
class SendSow
{
    use TransitionsProject;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    public function __invoke(Sow $sow, User $actor): Sow
    {
        Gate::forUser($actor)->authorize('send', $sow);

        DB::transaction(function () use ($sow, $actor): void {
            $locked = Sow::query()->lockForUpdate()->findOrFail($sow->getKey());

            if (! $locked->status->canTransitionTo(SowStatus::Sent)) {
                throw IllegalTransition::for('sow', $locked->status->value, SowStatus::Sent->value);
            }

            $project = Project::query()->lockForUpdate()->findOrFail($locked->project_id);

            if (blank($locked->body_html)) {
                throw ActionBlocked::guardFailed('The SOW has no content to send.');
            }

            $locked->forceFill(['status' => SowStatus::Sent, 'sent_at' => now()])->save();

            match ($project->status) {
                ProjectStatus::Draft => $this->moveProject($project, ProjectStatus::SowSent, $actor, null, properties: ['sow_version' => $locked->version]),
                ProjectStatus::SowSent => null,
                default => throw ActionBlocked::guardFailed('A SOW can only be sent while the project is in the proposal stage.'),
            };

            activity('sow')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('sent')
                ->withProperties(['version' => $locked->version, 'project_id' => $project->getKey()])
                ->log("SOW v{$locked->version} sent");
        });

        $sow->refresh()->loadMissing('project.organization');
        // The project conversation opens with the SOW (spec 14, 22: clients see nothing before it is sent).
        app(GetProjectThread::class)->ensure($sow->project, $actor);

        $this->dispatcher->toOrganization($sow->project->organization, new SowSent($sow, $sow->project->title, $sow->project->uuid));

        return $sow;
    }
}
