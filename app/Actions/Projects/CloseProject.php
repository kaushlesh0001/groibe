<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Actions\Reputation\CreateProofOfWorkDrafts;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Jobs\RecomputeReputation;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectClosed;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;

/**
 * accepted -> closed (spec 6.2, 9). Closing creates a proof-of-work draft for each contributor whose assignment
 * completed, asks the client for reviews and showcase consent, asks the contributors to finish their drafts and
 * review the client, and queues a reputation recompute for each contributor and the organization.
 */
class CloseProject
{
    use TransitionsProject;

    public function __construct(
        private CreateProofOfWorkDrafts $createDrafts,
        private NotificationDispatcher $dispatcher,
    ) {}

    public function __invoke(Project $project, User $actor): Project
    {
        $project = $this->moveProject(
            $project,
            ProjectStatus::Closed,
            $actor,
            attributes: ['closed_at' => now()],
            after: function (Project $locked) use ($actor): void {
                ($this->createDrafts)($locked, $actor);
            },
        );

        /** @var Collection<int, User> $contributors */
        $contributors = User::query()
            ->whereIn('id', Assignment::query()
                ->where('project_id', $project->getKey())
                ->where('status', AssignmentStatus::Completed->value)
                ->select('user_id'))
            ->get();

        $project->loadMissing('organization');

        $this->dispatcher->toOrganization($project->organization, new ProjectClosed($project, 'client'));
        $this->dispatcher->send($contributors, new ProjectClosed($project, 'contributor'));

        foreach ($contributors as $contributor) {
            RecomputeReputation::dispatch(userId: $contributor->getKey())->afterCommit();
        }

        RecomputeReputation::dispatch(organizationId: $project->organization_id)->afterCommit();

        return $project;
    }
}
