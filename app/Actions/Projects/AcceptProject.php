<?php

namespace App\Actions\Projects;

use App\Actions\Assignments\CompleteProjectAssignments;
use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectAccepted;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Projects\ProjectTransitionGuards;

/**
 * delivered -> accepted, once every milestone is client-accepted (spec 6.2), checked through the guards
 * interface. An admin can run it by hand; it also runs automatically when the last milestone is accepted,
 * on behalf of the user who triggered that (`automatic: true`, no permission check of its own).
 */
class AcceptProject
{
    use TransitionsProject;

    public function __construct(
        private ProjectTransitionGuards $guards,
        private CompleteProjectAssignments $completeAssignments,
        private NotificationDispatcher $dispatcher,
    ) {}

    public function __invoke(Project $project, User $actor, bool $automatic = false): Project
    {
        $project = $this->moveProject(
            $project,
            ProjectStatus::Accepted,
            $actor,
            $automatic ? null : 'transition',
            properties: $automatic ? ['automatic' => true] : [],
            guard: function (Project $locked): void {
                if (! $this->guards->allMilestonesAccepted($locked)) {
                    throw ActionBlocked::guardFailed('All milestones must be accepted by the client first.');
                }
            },
            after: function (Project $locked) use ($actor): void {
                ($this->completeAssignments)($locked, $actor);
            },
        );

        $project->loadMissing('deliveryManager');
        $notification = new ProjectAccepted($project);

        $this->dispatcher->toAdmins('projects.update', $notification, $project->deliveryManager);
        $this->dispatcher->send(
            User::query()->whereIn('id', Assignment::query()->where('project_id', $project->getKey())->where('status', AssignmentStatus::Completed->value)->select('user_id'))->get(),
            $notification,
        );

        return $project;
    }
}
