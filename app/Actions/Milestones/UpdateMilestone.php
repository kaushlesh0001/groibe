<?php

namespace App\Actions\Milestones;

use App\Actions\Work\Concerns\GuardsWorkPlan;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Edits the descriptive fields of a milestone. Status and order change only through their own Actions. */
class UpdateMilestone
{
    use GuardsWorkPlan;

    /** @param  array{title?: string, description?: string|null, due_date?: mixed, value?: numeric|null}  $data */
    public function __invoke(Milestone $milestone, User $actor, array $data): Milestone
    {
        Gate::forUser($actor)->authorize('update', $milestone);

        $milestone->loadMissing('project');
        $this->ensureProjectIsPlannable($milestone->project);

        $milestone->update(array_intersect_key($data, array_flip(['title', 'description', 'due_date', 'value'])));

        return $milestone;
    }
}
