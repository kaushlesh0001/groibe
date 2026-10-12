<?php

namespace App\Services\Exports\Definitions;

use App\Models\Profile;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributors (spec 15.2). Name and email, no phone, and no bio or notes (SCR-033). The rows are profiles, as on the screen.
 *
 * @extends ExportDefinition<Profile>
 */
class ContributorExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.contributors';
    }

    public function name(): string
    {
        return 'contributors';
    }

    public function logName(): string
    {
        return 'contributor_export';
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'City', 'State', 'Headline', 'Years of experience', 'Availability', 'Onboarding state', 'Account status', 'Skills', 'Joined'];
    }

    public function with(): array
    {
        return ['user.skills'];
    }

    /** @param  Profile  $model */
    public function row(Model $model): array
    {
        return [
            $model->user->name,
            $model->user->email,
            $model->city,
            $model->state,
            $model->headline,
            $model->experience_years,
            $this->label($model->availability),
            $this->label($model->onboarding_state),
            $this->label($model->user->status),
            $model->user->skills->pluck('name')->implode('; '),
            $this->date($model->user->created_at),
        ];
    }
}
