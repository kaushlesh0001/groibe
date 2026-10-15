<?php

namespace App\Actions\Projects;

use App\Enums\ClientDisplay;
use App\Enums\ShowcaseConsent;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The client sets how a project may be shown publicly: none, anonymous or named (spec 9, 12). Owners and managers only,
 * from `accepted` on. Every public read re-checks the consent, so setting it back to `none` hides published items at once.
 * Existing proof-of-work items follow the choice for their client display.
 */
class SetShowcaseConsent
{
    public function __invoke(Project $project, User $actor, ShowcaseConsent $consent): Project
    {
        Gate::forUser($actor)->authorize('manageShowcase', $project);
        app(Impersonation::class)->assertNotImpersonating();

        DB::transaction(function () use ($project, $actor, $consent): void {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->getKey());
            $from = $locked->showcase_consent;

            if ($from === $consent) {
                return;
            }

            $locked->forceFill(['showcase_consent' => $consent])->save();

            ProofOfWork::query()
                ->where('project_id', $locked->getKey())
                ->update(['client_display' => $consent === ShowcaseConsent::Named ? ClientDisplay::Named->value : ClientDisplay::Anonymous->value]);

            activity('project')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event('showcase_consent')
                ->withProperties(['from' => $from->value, 'to' => $consent->value])
                ->log("Showcase consent changed from {$from->value} to {$consent->value}");
        });

        return $project->refresh();
    }
}
