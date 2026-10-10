<?php

namespace App\Actions\Submissions;

use App\Actions\Submissions\Concerns\ReviewsSubmission;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** submitted -> qa_approved; the task becomes `approved` (spec 6.8, 6.9). The note is optional. */
class ApproveSubmission
{
    use ReviewsSubmission;

    public function __invoke(Submission $submission, User $actor, ?string $note = null): Submission
    {
        Gate::forUser($actor)->authorize('review', $submission);

        return $this->review($submission, SubmissionStatus::QaApproved, TaskStatus::Approved, $actor, filled(trim((string) $note)) ? trim((string) $note) : null);
    }
}
