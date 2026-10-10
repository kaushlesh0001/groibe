<?php

namespace App\Actions\Tasks;

use App\Actions\Files\AttachPrivateFile;
use App\Actions\Tasks\Concerns\MovesTask;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Submission;
use App\Models\Task;
use App\Models\User;
use App\Notifications\SubmissionReceived;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * in_progress -> submitted, by the assignee (spec 6.7, 6.8). Creates the next submission version with
 * its files in the private collection. A resubmission after changes is a new row with version + 1.
 */
class SubmitWork
{
    use MovesTask;

    public function __construct(private NotificationDispatcher $dispatcher, private AttachPrivateFile $files) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function __invoke(Task $task, User $actor, ?string $note, array $files = []): Submission
    {
        Gate::forUser($actor)->authorize('work', $task);

        $note = trim((string) $note);

        if ($note === '' && $files === []) {
            throw ActionBlocked::guardFailed('Add a note or at least one file to submit.');
        }

        if (count($files) > (int) config('groibe.uploads.submission_max_files')) {
            throw ActionBlocked::guardFailed('You can attach up to '.config('groibe.uploads.submission_max_files').' files per submission.');
        }

        $maxKb = (int) config('groibe.uploads.submission_max_kb');

        foreach ($files as $file) {
            $this->files->validate($file, $maxKb);
        }

        $submission = null;

        $this->moveTask($task, TaskStatus::InProgress, TaskStatus::Submitted, $actor, within: function (Task $locked) use ($actor, $note, $files, $maxKb, &$submission): void {
            $submission = Submission::query()->create([
                'task_id' => $locked->getKey(),
                'assignment_id' => $locked->assignment_id,
                'user_id' => $actor->getKey(),
                'note' => $note === '' ? null : $note,
                'status' => SubmissionStatus::Submitted,
                'version' => (int) Submission::query()->where('task_id', $locked->getKey())->max('version') + 1,
            ]);

            foreach ($files as $file) {
                $this->files->store($submission, $file, $actor, Submission::FILES, $maxKb);
            }

            activity('submission')
                ->causedBy($actor)
                ->performedOn($submission)
                ->event('submitted')
                ->withProperties(['task_id' => $locked->getKey(), 'version' => $submission->version, 'files' => count($files)])
                ->log("Submission v{$submission->version} received");
        });

        /** @var Submission $submission */
        $task->loadMissing('milestone.project.deliveryManager');

        $this->dispatcher->toAdmins('submissions.approve', new SubmissionReceived($submission, $task, $task->milestone->project, $actor->name), $task->milestone->project->deliveryManager);

        return $submission;
    }
}
