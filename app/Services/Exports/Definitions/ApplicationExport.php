<?php

namespace App\Services\Exports\Definitions;

use App\Models\Application;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Applications to open roles (spec 15.2). Applicant name and email, no phone. The motivation, experience and availability
 * answers and the admin note are free text and stay out (SCR-033).
 *
 * @extends ExportDefinition<Application>
 */
class ApplicationExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'applications';
    }

    public function logName(): string
    {
        return 'application_export';
    }

    public function headings(): array
    {
        return ['Role', 'Applicant', 'Email', 'Status', 'Expected amount', 'Applied', 'Shortlisted', 'Decided'];
    }

    public function with(): array
    {
        return ['openRole:id,title', 'user:id,name,email'];
    }

    /** @param  Application  $model */
    public function row(Model $model): array
    {
        return [
            $model->openRole->title,
            $model->user->name,
            $model->user->email,
            $this->label($model->status),
            $model->expected_amount,
            $this->date($model->created_at),
            $this->date($model->shortlisted_at),
            $this->date($model->decided_at),
        ];
    }
}
