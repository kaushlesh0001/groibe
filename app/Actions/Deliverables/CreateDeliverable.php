<?php

namespace App\Actions\Deliverables;

use App\Actions\Deliverables\Concerns\BuildsDeliverables;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bundles approved submissions into a client-facing deliverable on a milestone (spec 3, 7.3). */
class CreateDeliverable
{
    use BuildsDeliverables;

    /** @param  array{title: string, summary?: string|null, submission_ids: array<int, int|string>}  $data */
    public function __invoke(Milestone $milestone, User $actor, array $data): Deliverable
    {
        Gate::forUser($actor)->authorize('create', Deliverable::class);

        return DB::transaction(function () use ($milestone, $data): Deliverable {
            $locked = Milestone::query()->lockForUpdate()->with('project')->findOrFail($milestone->getKey());

            $this->ensureMilestoneIsBeingBuilt($locked);

            $submissions = $this->resolveApprovedSubmissions($locked, $data['submission_ids']);

            $deliverable = Deliverable::query()->create([
                'milestone_id' => $locked->getKey(),
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
            ]);

            $deliverable->submissions()->attach($submissions->modelKeys());

            return $deliverable;
        });
    }
}
