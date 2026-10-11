<?php

namespace App\Actions\Reputation;

use App\Enums\AssignmentStatus;
use App\Enums\ClientDisplay;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ShowcaseConsent;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Spec 9: on project close, a draft ProofOfWork for each contributor whose assignment completed. Runs inside the
 * close transaction. Idempotent per (contributor, project), so a second close cannot duplicate (SCR-020).
 *
 * Pass 6 decisions: removed assignments get none. The draft title comes from the role and project type, never
 * the project title, because a project title can name the client. The summary is left for the contributor to
 * write, and the skills are the project skills the contributor also holds (all project skills if none overlap).
 */
class CreateProofOfWorkDrafts
{
    /** @return Collection<int, ProofOfWork> the drafts created by this call */
    public function __invoke(Project $project, ?User $actor): Collection
    {
        $project->loadMissing('projectType', 'skills');

        $assignments = Assignment::query()
            ->where('project_id', $project->getKey())
            ->where('status', AssignmentStatus::Completed->value)
            ->with('user.skills:id')
            ->get();

        $created = collect();

        foreach ($assignments as $assignment) {
            if (ProofOfWork::query()->where('user_id', $assignment->user_id)->where('project_id', $project->getKey())->exists()) {
                continue;
            }

            $proof = ProofOfWork::query()->create([
                'user_id' => $assignment->user_id,
                'project_id' => $project->getKey(),
                'title' => trim($assignment->role_title.': '.data_get($project, 'projectType.name', 'Delivery project'), ': '),
                'role_title' => $assignment->role_title,
                'visibility' => ProofVisibility::Private,
                'status' => ProofStatus::Draft,
                'client_display' => $this->displayFor($project),
            ]);

            $held = $assignment->user->skills->pluck('id');
            $skillIds = $project->skills->pluck('id')->intersect($held);
            $proof->skills()->sync($skillIds->isNotEmpty() ? $skillIds->all() : $project->skills->pluck('id')->all());

            activity('proof_of_work')
                ->causedBy($actor)
                ->performedOn($proof)
                ->event('drafted')
                ->withProperties(['project_id' => $project->getKey(), 'reason' => 'project_closed'])
                ->log('Proof-of-work draft created when the project closed');

            $created->push($proof);
        }

        return $created;
    }

    private function displayFor(Project $project): ClientDisplay
    {
        return $project->showcase_consent === ShowcaseConsent::Named ? ClientDisplay::Named : ClientDisplay::Anonymous;
    }
}
