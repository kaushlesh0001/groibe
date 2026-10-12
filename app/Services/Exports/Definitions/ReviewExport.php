<?php

namespace App\Services\Exports\Definitions;

use App\Enums\ReviewRevieweeType;
use App\Models\Review;
use App\Services\Exports\ExportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Reviews (spec 15.2): the ratings and where each review stands, never the comment (SCR-033). Reviews of client organizations
 * are admin-only data (spec 9), which is why this export is too.
 *
 * @extends ExportDefinition<Review>
 */
class ReviewExport extends ExportDefinition
{
    public function permission(): string
    {
        return 'import_export.projects';
    }

    public function name(): string
    {
        return 'reviews';
    }

    public function logName(): string
    {
        return 'review_export';
    }

    public function headings(): array
    {
        return ['Project', 'Reviewer', 'About', 'About type', 'Direction', 'Quality', 'Communication', 'Reliability', 'Timeliness', 'Clarity', 'Professionalism', 'Feedback time', 'Fairness', 'Status', 'Published'];
    }

    public function with(): array
    {
        return ['project:id,code', 'reviewer:id,name', 'revieweeUser:id,name', 'revieweeOrganization:id,name'];
    }

    /** @param  Review  $model */
    public function row(Model $model): array
    {
        $about = $model->reviewee_type === ReviewRevieweeType::Organization ? $model->revieweeOrganization : $model->revieweeUser;

        return [
            $model->project->code,
            $model->reviewer->name,
            $about?->name,
            $this->label($model->reviewee_type),
            $this->label($model->direction),
            $model->quality,
            $model->communication,
            $model->reliability,
            $model->timeliness,
            $model->clarity,
            $model->professionalism,
            $model->feedback_time,
            $model->fairness,
            $this->label($model->status),
            $this->date($model->published_at),
        ];
    }
}
