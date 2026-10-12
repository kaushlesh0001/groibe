<?php

namespace App\Services\Exports\Definitions;

use App\Models\Project;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Projects (spec 15.2). Summary and scope text stay out (SCR-033). The contract value is a figure the delivery team already
 * holds; payouts and invoices have their own finance export.
 *
 * @extends ExportDefinition<Project>
 */
class ProjectExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'projects';
    }

    public function logName(): string
    {
        return 'project_export';
    }

    public function headings(): array
    {
        return ['Code', 'Title', 'Organization', 'Project type', 'Status', 'Contract value', 'Currency', 'Start', 'Due', 'Delivery manager', 'Showcase consent', 'Created', 'Closed'];
    }

    public function with(): array
    {
        return ['organization:id,name', 'projectType:id,name', 'deliveryManager:id,name'];
    }

    /** @param  Project  $model */
    public function row(Model $model): array
    {
        return [
            $model->code,
            $model->title,
            $model->organization->name,
            $model->projectType?->name,
            $this->label($model->status),
            $model->contract_value,
            $model->currency,
            $this->date($model->start_date),
            $this->date($model->due_date),
            $model->deliveryManager?->name,
            $this->label($model->showcase_consent),
            $this->date($model->created_at),
            $this->date($model->closed_at),
        ];
    }
}
