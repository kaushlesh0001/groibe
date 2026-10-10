<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectDelivered;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Projects\ProjectTransitionGuards;

/**
 * in_qa -> delivered, an admin action allowed once every milestone is shared or later (spec 6.2).
 * If the client already accepted every milestone, the project goes straight on to `accepted`.
 */
class MarkProjectDelivered
{
    use TransitionsProject;

    public function __construct(private ProjectTransitionGuards $guards, private AcceptProject $acceptProject, private NotificationDispatcher $dispatcher) {}

    public function __invoke(Project $project, User $actor): Project
    {
        $project = $this->moveProject($project, ProjectStatus::Delivered, $actor, guard: function (Project $locked): void {
            if (! $this->guards->allMilestonesShared($locked)) {
                throw ActionBlocked::guardFailed('Every milestone must be shared with the client before the project is delivered.');
            }
        });

        $project->loadMissing('organization');
        $this->dispatcher->toOrganization($project->organization, new ProjectDelivered($project));

        if ($this->guards->allMilestonesAccepted($project)) {
            return ($this->acceptProject)($project, $actor, automatic: true);
        }

        return $project;
    }
}
