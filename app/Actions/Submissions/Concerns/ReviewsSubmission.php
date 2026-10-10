<?php

namespace App\Actions\Submissions\Concerns;

use App\Actions\Milestones\SyncMilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\SubmissionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\Submission;
use App\Models\Task;
use App\Models\User;
use App\Notifications\SubmissionReviewed;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Internal QA of a submission (spec 6.8, 6.9): the submission and its task move together, the milestone
 * follows, and the contributor is told. Callers authorize first.
 */
trait ReviewsSubmission
{
    protected function review(Submission $submission, SubmissionStatus $to, TaskStatus $taskTo, User $actor, ?string $note): Submission
    {
        DB::transaction(function () use ($submission, $to, $taskTo, $actor, $note): void {
            $locked = Submission::query()->lockForUpdate()->findOrFail($submission->getKey());
            $task = Task::query()->lockForUpdate()->with('milestone.project')->findOrFail($locked->task_id);

            if (! $locked->status->canTransitionTo($to)) {
                throw IllegalTransition::for('submission', $locked->status->value, $to->value);
            }

            if (! $task->status->canTransitionTo($taskTo)) {
                throw IllegalTransition::for('task', $task->status->value, $taskTo->value);
            }

            if (! in_array($task->milestone->project->status, [ProjectStatus::Active, ProjectStatus::InQa], true)) {
                throw ActionBlocked::guardFailed('Submissions can only be reviewed while the project is active or in QA.');
            }

            $locked->forceFill([
                'status' => $to,
                'qa_note' => $note,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $task->forceFill([
                'status' => $taskTo,
                'approved_at' => $taskTo === TaskStatus::Approved ? now() : null,
                'approved_by' => $taskTo === TaskStatus::Approved ? $actor->getKey() : null,
            ])->save();

            activity('submission')
                ->causedBy($actor)
                ->performedOn($locked)
                ->event($to->value)
                ->withProperties(['task_id' => $task->getKey(), 'version' => $locked->version, 'project_id' => $task->project_id])
                ->log("Submission v{$locked->version} moved to {$to->value}");

            activity('task')
                ->causedBy($actor)
                ->performedOn($task)
                ->event($taskTo->value)
                ->withProperties(['to' => $taskTo->value, 'submission_id' => $locked->getKey(), 'project_id' => $task->project_id])
                ->log("Task moved to {$taskTo->value} by QA");

            app(SyncMilestoneStatus::class)($task->milestone);
        });

        $submission->refresh()->loadMissing('user', 'task.milestone.project');

        app(NotificationDispatcher::class)->send(
            $submission->user,
            new SubmissionReviewed($submission, $submission->task, $submission->task->milestone->project, approved: $to === SubmissionStatus::QaApproved),
        );

        return $submission;
    }
}
