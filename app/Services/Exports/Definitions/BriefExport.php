<?php

namespace App\Services\Exports\Definitions;

use App\Models\Brief;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Briefs (spec 15.2). The problem, desired outcome, notes to the client and internal notes are free text and stay out (SCR-033).
 *
 * @extends ExportDefinition<Brief>
 */
class BriefExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'briefs';
    }

    public function logName(): string
    {
        return 'brief_export';
    }

    public function headings(): array
    {
        return ['Title', 'Organization', 'Project type', 'Status', 'Budget band', 'Timeline band', 'Target start', 'Deadline', 'Confidentiality', 'Submitted', 'Project code'];
    }

    public function with(): array
    {
        return ['organization:id,name', 'projectType:id,name', 'convertedProject:id,code'];
    }

    /** @param  Brief  $model */
    public function row(Model $model): array
    {
        return [
            $model->title,
            $model->organization->name,
            $model->projectType?->name,
            $this->label($model->status),
            $model->budget_band,
            $model->timeline_band,
            $this->date($model->target_start),
            $this->date($model->deadline),
            $this->label($model->confidentiality),
            $this->dateTime($model->submitted_at),
            $model->convertedProject?->code,
        ];
    }
}
