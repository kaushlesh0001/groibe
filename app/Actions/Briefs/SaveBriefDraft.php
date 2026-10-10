<?php

namespace App\Actions\Briefs;

use App\Enums\BriefStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Brief;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates or updates a brief while the client may still edit it (draft, or needs_info per spec 6.1).
 * It never changes status; submitting and resuming are their own Actions.
 */
class SaveBriefDraft
{
    /**
     * @param  array<string, mixed>  $data  title, problem, desired_outcome, project_type_id, budget_band, timeline_band, target_start, deadline, confidentiality
     * @param  list<int>  $skillIds
     */
    public function __invoke(User $actor, ?Brief $brief, array $data, array $skillIds = []): Brief
    {
        $fields = array_intersect_key($data, array_flip([
            'title', 'problem', 'desired_outcome', 'project_type_id', 'budget_band', 'timeline_band',
            'target_start', 'deadline', 'confidentiality',
        ]));

        return DB::transaction(function () use ($actor, $brief, $fields, $skillIds): Brief {
            if ($brief === null) {
                Gate::forUser($actor)->authorize('create', Brief::class);

                $organization = $actor->primaryOrganization() ?? throw ActionBlocked::guardFailed('Your account is not linked to an organization yet.');

                $brief = Brief::create([...$fields, 'organization_id' => $organization->getKey(), 'status' => BriefStatus::Draft]);
            } else {
                Gate::forUser($actor)->authorize('update', $brief);

                $brief->fill($fields)->save();
            }

            $brief->skills()->sync($skillIds);

            return $brief;
        });
    }
}
