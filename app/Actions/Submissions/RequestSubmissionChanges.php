<?php

namespace App\Actions\Submissions;

use App\Actions\Submissions\Concerns\ReviewsSubmission;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** submitted -> qa_changes_requested; the task becomes `changes_requested` (spec 6.8, 6.9). The note is required. */
class RequestSubmissionChanges
{
    use ReviewsSubmission;

    public function __invoke(Submission $submission, User $actor, string $note): Submission
    {
        Gate::forUser($actor)->authorize('review', $submission);

        $note = trim($note);

        if ($note === '') {
            throw ActionBlocked::guardFailed('Tell the contributor what needs to change.');
        }

        return $this->review($submission, SubmissionStatus::QaChangesRequested, TaskStatus::ChangesRequested, $actor, $note);
    }
}
