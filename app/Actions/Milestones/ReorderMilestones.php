<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Exceptions\ActionBlocked;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Sets the order of a project's milestones. The list must contain every milestone of the project exactly once. */
class ReorderMilestones
{
    use GuardsWorkPlan;

    /** @param  array<int, int>  $orderedIds  milestone ids in the new order; keys are ignored (they come from the browser) */
    public function __invoke(Project $project, User $actor, array $orderedIds): void
    {
        Gate::forUser($actor)->authorize('reorder', Milestone::class);

        DB::transaction(function () use ($project, $orderedIds): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());

            $this->ensureProjectIsPlannable($locked);

            $existing = Milestone::query()->where('project_id', $locked->getKey())->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count(array_unique($orderedIds)) || array_diff($existing, $orderedIds) !== [] || array_diff($orderedIds, $existing) !== []) {
                throw ActionBlocked::guardFailed('The new order must list every milestone of the project exactly once.');
            }

            foreach (array_values($orderedIds) as $position => $id) {
                Milestone::query()->whereKey($id)->update(['sort' => $position + 1]);
            }
        });
    }
}
