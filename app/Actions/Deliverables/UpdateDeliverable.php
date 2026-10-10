<?php

namespace App\Actions\Deliverables;

use App\Actions\Deliverables\Concerns\BuildsDeliverables;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Edits a deliverable and the submissions bundled in it, until its milestone is shared. */
class UpdateDeliverable
{
    use BuildsDeliverables;

    /** @param  array{title?: string, summary?: string|null, submission_ids?: array<int, int|string>}  $data */
    public function __invoke(Deliverable $deliverable, User $actor, array $data): Deliverable
    {
        Gate::forUser($actor)->authorize('update', $deliverable);

        return DB::transaction(function () use ($deliverable, $data): Deliverable {
            $locked = Deliverable::query()->lockForUpdate()->findOrFail($deliverable->getKey());
            $milestone = Milestone::query()->lockForUpdate()->with('project')->findOrFail($locked->milestone_id);

            $this->ensureMilestoneIsBeingBuilt($milestone);

            $submissions = array_key_exists('submission_ids', $data)
                ? $this->resolveApprovedSubmissions($milestone, $data['submission_ids'])
                : null;

            $locked->fill(array_intersect_key($data, array_flip(['title', 'summary'])))->save();

            if ($submissions !== null) {
                $locked->submissions()->sync($submissions->modelKeys());
            }

            return $locked;
        });
    }
}
