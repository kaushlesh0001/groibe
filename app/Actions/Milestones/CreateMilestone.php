<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Adds a milestone at the end of the project's plan (spec 6.9). It starts as `planned`. */
class CreateMilestone
{
    use GuardsWorkPlan;

    /** @param  array{title: string, description?: string|null, due_date?: mixed, value?: numeric|null}  $data */
    public function __invoke(Project $project, User $actor, array $data): Milestone
    {
        Gate::forUser($actor)->authorize('create', Milestone::class);

        return DB::transaction(function () use ($project, $data): Milestone {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $this->ensureProjectIsPlannable($locked);

            return Milestone::query()->create([
                'project_id' => $locked->getKey(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'value' => $data['value'] ?? null,
                'status' => MilestoneStatus::Planned,
                'sort' => Milestone::query()->where('project_id', $locked->getKey())->max('sort') + 1,
            ]);
        });
    }
}
