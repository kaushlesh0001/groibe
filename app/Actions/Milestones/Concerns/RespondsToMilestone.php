<?php

namespace App\Actions\Milestones\Concerns;

use App\Actions\Projects\AcceptProject;
use App\Actions\Projects\ReopenProjectForChanges;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Milestone;
use App\Models\User;
use App\Notifications\MilestoneResponded;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Projects\ProjectTransitionGuards;
use Illuminate\Support\Facades\DB;

/**
 * The client's answer on a shared milestone (spec 6.9, 12) and the project moves it can trigger (spec 6.2).
 * Callers authorize first.
 */
trait RespondsToMilestone
{
    protected function respond(Milestone $milestone, User $actor, MilestoneStatus $to, ?string $note): Milestone
    {
        DB::transaction(function () use ($milestone, $actor, $to, $note): void {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            if (! $locked->status->canTransitionTo($to)) {
                throw IllegalTransition::for('milestone', $locked->status->value, $to->value);
            }

            $accepted = $to === MilestoneStatus::ClientAccepted;

            $locked->forceFill([
                'status' => $to,
                'client_note' => $note,
                'client_accepted_at' => $accepted ? now() : null,
                'client_accepted_by' => $accepted ? $actor->getKey() : null,
            ])->save();

            activity('milestone')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['from' => 'shared', 'to' => $to->value, 'project_id' => $locked->project_id, 'has_note' => $note !== null])
                ->log("Client moved the milestone to {$to->value}");

            $project = $locked->project;

            if ($accepted && $project->status === ProjectStatus::Delivered && app(ProjectTransitionGuards::class)->allMilestonesAccepted($project)) {
                app(AcceptProject::class)($project, $actor, automatic: true);
            }

            if (! $accepted && $project->status === ProjectStatus::Delivered) {
                app(ReopenProjectForChanges::class)($project, $actor);
            }
        });

        $milestone->refresh()->loadMissing('project.deliveryManager');

        app(NotificationDispatcher::class)->toAdmins(
            'deliverables.update',
            new MilestoneResponded($milestone, $milestone->project, $actor->name, accepted: $to === MilestoneStatus::ClientAccepted),
            $milestone->project->deliveryManager,
        );

        return $milestone;
    }
}
