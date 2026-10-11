<?php

namespace App\Actions\Reputation;

use App\Models\ProofOfWork;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The contributor edits the wording of a draft (spec 9, 11 Portfolio): title, summary, contribution text and outcome
 * metrics. Allowed only while the item is a draft, so published words always passed an admin.
 */
class UpdateProofOfWork
{
    /**
     * @param  array{title?: string, summary?: ?string, contribution_text?: ?string, outcome_metrics?: list<array{label: string, value: string}>}  $data
     */
    public function __invoke(ProofOfWork $proof, User $actor, array $data): ProofOfWork
    {
        Gate::forUser($actor)->authorize('edit', $proof);

        $attributes = array_intersect_key($data, array_flip(['title', 'summary', 'contribution_text']));

        if (array_key_exists('outcome_metrics', $data)) {
            $attributes['outcome_metrics'] = collect($data['outcome_metrics'])
                ->map(fn (array $row): array => ['label' => trim($row['label']), 'value' => trim($row['value'])])
                ->filter(fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
                ->values()
                ->all();
        }

        $proof->fill($attributes)->save();

        return $proof->refresh();
    }
}
