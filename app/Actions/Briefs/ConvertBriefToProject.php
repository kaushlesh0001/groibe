<?php

namespace App\Actions\Briefs;

use App\Actions\Briefs\Concerns\TransitionsBrief;
use App\Actions\Projects\CreateProject;
use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\Project;
use App\Models\User;
use App\Notifications\BriefStatusChanged;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/** scoped -> converted: creates a draft Project from the brief and copies its skills (spec 6.1, 13). */
class ConvertBriefToProject
{
    use TransitionsBrief;

    public function __construct(private CreateProject $createProject, private NotificationDispatcher $dispatcher) {}

    /**
     * @param  array<string, mixed>  $overrides  title, summary, scope_summary, contract_value, start_date, due_date, delivery_manager_id
     */
    public function __invoke(Brief $brief, User $actor, array $overrides = []): Project
    {
        $project = null;

        DB::transaction(function () use ($brief, $actor, $overrides, &$project): void {
            $this->move($brief, BriefStatus::Converted, $actor, 'convert', guard: function (Brief $locked) use ($actor, $overrides, &$project): void {
                $locked->loadMissing('skills:id');

                $project = ($this->createProject)([
                    'brief_id' => $locked->getKey(),
                    'organization_id' => $locked->organization_id,
                    'title' => $locked->title,
                    'summary' => $locked->problem,
                    'scope_summary' => $locked->desired_outcome,
                    'project_type_id' => $locked->project_type_id,
                    'start_date' => $locked->target_start,
                    'due_date' => $locked->deadline,
                    'delivery_manager_id' => $locked->assigned_admin_id ?? $actor->getKey(),
                    ...$overrides,
                ], $actor, $locked->skills->modelKeys());

                $locked->forceFill(['converted_project_id' => $project->getKey()]);
            });
        });

        /** @var Project $project */
        $brief->refresh()->loadMissing(['organization', 'submitter']);
        $this->dispatcher->toOrganization($brief->organization, new BriefStatusChanged($brief, BriefStatus::Converted), $brief->submitter);

        return $project;
    }
}
