<?php

namespace App\Services\Exports\Definitions;

use App\Models\Organization;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Client organizations (spec 15.2). Company details only, with no description or notes (SCR-033).
 *
 * @extends ExportDefinition<Organization>
 */
class OrganizationExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'organizations';
    }

    public function logName(): string
    {
        return 'organization_export';
    }

    public function headings(): array
    {
        return ['Name', 'Legal name', 'GSTIN', 'Website', 'Industry', 'Size', 'City', 'State', 'Status', 'Verified on', 'Can submit briefs', 'Public', 'Featured', 'Zoho CRM id', 'Created'];
    }

    /** @param  Organization  $model */
    public function row(Model $model): array
    {
        return [
            $model->name,
            $model->legal_name,
            $model->gstin,
            $model->website,
            $model->industry,
            $model->size_band,
            $model->city,
            $model->state,
            $this->label($model->status),
            $this->date($model->verified_at),
            $this->yesNo($model->can_submit_briefs),
            $this->yesNo($model->is_public),
            $this->yesNo($model->is_featured),
            $model->zoho_crm_id,
            $this->date($model->created_at),
        ];
    }
}
