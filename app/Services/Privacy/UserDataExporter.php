<?php

namespace App\Services\Privacy;

use App\Enums\PayoutStatus;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Models\AgreementAcceptance;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\EmailLog;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Payout;
use App\Models\ProofOfWork;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Builds a copy of one person's data for a data request (spec 17.2, SCR-028). It holds what the person gave Groibe or what
 * Groibe recorded about them, as they could see it in the app. It never holds:
 *  - what only staff may see: internal messages and comments, admin notes on applications, removal reasons, payout notes, who
 *    planned a payout, internal reviews, and reviews of an organization written by someone else;
 *  - anyone else's personal data;
 *  - secrets: the password hash, the remember token, the two-factor secret and recovery codes.
 *
 * Every list is read with explicit queries and eager loads, so the cost does not grow with a request's size.
 */
class UserDataExporter
{
    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $user->loadMissing('profile');

        return [
            'generated_at' => now()->toIso8601String(),
            'about_this_file' => 'A copy of the personal data Groibe holds about you. It leaves out notes only Groibe staff can see, and other people\'s data.',
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'email_verified_at' => $this->time($user->email_verified_at),
                'last_login_at' => $this->time($user->last_login_at),
                'timezone' => $user->timezone,
                'created_at' => $this->time($user->created_at),
                'two_factor_enabled' => $user->hasTwoFactor(),
            ],
            'profile' => $user->profile === null ? null : [
                'headline' => $user->profile->headline,
                'bio' => $user->profile->bio,
                'phone' => $user->profile->phone,
                'city' => $user->profile->city,
                'state' => $user->profile->state,
                'country' => $user->profile->country,
                'linkedin_url' => $user->profile->linkedin_url,
                'portfolio_url' => $user->profile->portfolio_url,
                'availability' => $user->profile->availability?->value,
                'hours_per_week' => $user->profile->hours_per_week,
                'work_modes' => $user->profile->work_modes,
                'experience_years' => $user->profile->experience_years,
                'languages' => $user->profile->languages,
                'is_public' => $user->profile->is_public,
                'onboarding_state' => $user->profile->onboarding_state?->value,
                'has_photo' => $user->profile->photo_path !== null,
            ],
            'skills' => $this->skills($user),
            'organizations' => $this->organizations($user),
            'agreements_accepted' => $this->agreements($user),
            'briefs_submitted' => $this->briefs($user),
            'applications' => $this->applications($user),
            'project_seats' => $this->assignments($user),
            'tasks' => $this->tasks($user),
            'task_comments' => $this->taskComments($user),
            'submissions' => $this->submissions($user),
            'messages' => $this->messages($user),
            'proof_of_work' => $this->proofOfWork($user),
            'reviews_written' => $this->reviewsWritten($user),
            'reviews_received' => $this->reviewsReceived($user),
            'payouts' => $this->payouts($user),
            'notification_preferences' => NotificationPreference::query()->where('user_id', $user->getKey())->orderBy('category')->get()
                ->map(fn (NotificationPreference $row): array => ['category' => $row->category, 'in_app' => $row->database, 'email' => $row->mail])->all(),
            'notifications' => $this->notifications($user),
            'emails_sent_to_you' => EmailLog::query()->where('user_id', $user->getKey())->orderBy('id')->get()
                ->map(fn (EmailLog $row): array => ['kind' => class_basename($row->template), 'status' => $row->status->value, 'sent_at' => $this->time($row->sent_at)])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function skills(User $user): array
    {
        return DB::table('skill_user')->join('skills', 'skills.id', '=', 'skill_user.skill_id')
            ->where('skill_user.user_id', $user->getKey())->orderBy('skills.name')
            ->get(['skills.name', 'skill_user.level', 'skill_user.years', 'skill_user.is_verified'])
            ->map(fn (object $row): array => ['skill' => $row->name, 'level' => $row->level, 'years' => $row->years, 'verified' => (bool) $row->is_verified])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function organizations(User $user): array
    {
        return $user->organizations()->get()->map(function ($organization): array {
            $membership = $organization->getRelation('pivot');

            return [
                'organization' => $organization->name,
                'role' => $membership->org_role->value,
                'designation' => $membership->designation,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function agreements(User $user): array
    {
        return AgreementAcceptance::query()->with('template:id,type,title,version')->where('user_id', $user->getKey())->orderBy('accepted_at')->get()
            ->map(fn (AgreementAcceptance $row): array => [
                'agreement' => $row->template->title,
                'type' => $row->template->type->value,
                'version' => $row->template->version,
                'accepted_at' => $this->time($row->accepted_at),
                'ip' => $row->ip,
                'browser' => $row->user_agent,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function briefs(User $user): array
    {
        return Brief::query()->with('organization:id,name')->where('submitted_by', $user->getKey())->orderBy('id')->get()
            ->map(fn (Brief $brief): array => [
                'title' => $brief->title,
                'organization' => $brief->organization->name,
                'status' => $brief->status->value,
                'problem' => $brief->problem,
                'desired_outcome' => $brief->desired_outcome,
                'budget_band' => $brief->budget_band,
                'timeline_band' => $brief->timeline_band,
                'submitted_at' => $this->time($brief->submitted_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function applications(User $user): array
    {
        return Application::query()->with('openRole:id,title')->where('user_id', $user->getKey())->orderBy('id')->get()
            ->map(fn (Application $application): array => [
                'role' => $application->openRole->title,
                'status' => $application->status->value,
                'motivation' => $application->motivation,
                'relevant_experience' => $application->relevant_experience,
                'availability_note' => $application->availability_note,
                'expected_amount' => $application->expected_amount,
                'applied_at' => $this->time($application->created_at),
                'shortlisted_at' => $this->time($application->shortlisted_at),
                'decided_at' => $this->time($application->decided_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function assignments(User $user): array
    {
        return Assignment::query()->with('project:id,code,title')->where('user_id', $user->getKey())->orderBy('id')->get()
            ->map(fn (Assignment $seat): array => [
                'project' => $seat->project->code.' '.$seat->project->title,
                'role' => $seat->role_title,
                'status' => $seat->status->value,
                'rate_type' => $seat->rate_type->value,
                'agreed_amount' => $seat->agreed_amount,
                'currency' => $seat->currency,
                'invited_at' => $this->time($seat->invited_at),
                'accepted_at' => $this->time($seat->accepted_at),
                'completed_at' => $this->time($seat->completed_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function tasks(User $user): array
    {
        return Task::query()->with('project:id,code')
            ->whereIn('assignment_id', Assignment::query()->where('user_id', $user->getKey())->select('id'))
            ->orderBy('id')->get()
            ->map(fn (Task $task): array => [
                'project' => $task->project->code,
                'title' => $task->title,
                'status' => $task->status->value,
                'due_date' => $this->time($task->due_date),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function taskComments(User $user): array
    {
        return TaskComment::query()->with('task.project:id,code')->where('user_id', $user->getKey())->where('is_internal', false)->orderBy('id')->get()
            ->map(fn (TaskComment $comment): array => [
                'task' => $comment->task->title,
                'body' => $comment->body,
                'written_at' => $this->time($comment->created_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function submissions(User $user): array
    {
        return Submission::query()->with(['task:id,title', 'media'])->where('user_id', $user->getKey())->orderBy('id')->get()
            ->map(fn (Submission $submission): array => [
                'task' => $submission->task->title,
                'version' => $submission->version,
                'note' => $submission->note,
                'status' => $submission->status->value,
                'review_note' => $submission->qa_note,
                'files' => $submission->media->map(fn ($file): string => $file->name.'.'.$file->extension)->values()->all(),
                'submitted_at' => $this->time($submission->created_at),
            ])->all();
    }

    /** Messages the person wrote. Internal messages are staff-only and are never included. @return list<array<string, mixed>> */
    private function messages(User $user): array
    {
        return Message::query()->with('thread.project:id,code')->where('user_id', $user->getKey())->where('is_internal', false)->orderBy('id')->get()
            ->map(fn (Message $message): array => [
                'conversation' => $message->thread->subject,
                'project' => $message->thread->project?->code,
                'body' => $message->body,
                'sent_at' => $this->time($message->created_at),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function proofOfWork(User $user): array
    {
        return ProofOfWork::query()->with('project:id,code')->where('user_id', $user->getKey())->orderBy('id')->get()
            ->map(fn (ProofOfWork $proof): array => [
                'title' => $proof->title,
                'project' => $proof->project?->code,
                'role' => $proof->role_title,
                'summary' => $proof->summary,
                'contribution' => $proof->contribution_text,
                'outcome_metrics' => $proof->outcome_metrics,
                'visibility' => $proof->visibility->value,
                'status' => $proof->status->value,
                'published_at' => $this->time($proof->published_at),
            ])->all();
    }

    /** Reviews the person wrote. Their own words are theirs to have, including a review of a client organization. @return list<array<string, mixed>> */
    private function reviewsWritten(User $user): array
    {
        return Review::query()->with('project:id,code')->where('reviewer_id', $user->getKey())
            ->where('direction', '!=', ReviewDirection::AdminToContributor->value)->orderBy('id')->get()
            ->map(fn (Review $review): array => $this->review($review))->all();
    }

    /** Published reviews about the person. Moderation state and internal reviews stay out. @return list<array<string, mixed>> */
    private function reviewsReceived(User $user): array
    {
        return Review::query()->with('project:id,code')
            ->where('reviewee_type', 'user')->where('reviewee_id', $user->getKey())
            ->where('status', ReviewStatus::Published->value)
            ->where('direction', '!=', ReviewDirection::AdminToContributor->value)->orderBy('id')->get()
            ->map(fn (Review $review): array => $this->review($review))->all();
    }

    /** @return array<string, mixed> */
    private function review(Review $review): array
    {
        return [
            'project' => $review->project->code,
            'direction' => $review->direction->value,
            'quality' => $review->quality,
            'communication' => $review->communication,
            'reliability' => $review->reliability,
            'timeliness' => $review->timeliness,
            'clarity' => $review->clarity,
            'professionalism' => $review->professionalism,
            'feedback_time' => $review->feedback_time,
            'fairness' => $review->fairness,
            'comment' => $review->comment,
            'published_at' => $this->time($review->published_at),
        ];
    }

    /** What the contributor sees on their payouts page: from approval on, without notes or who planned it. @return list<array<string, mixed>> */
    private function payouts(User $user): array
    {
        return Payout::query()->with(['project:id,code', 'milestone:id,title'])->where('user_id', $user->getKey())
            ->whereNotNull('approved_at')->orderBy('id')->get()
            ->map(fn (Payout $payout): array => [
                'project' => $payout->project->code,
                'milestone' => $payout->milestone?->title,
                'amount' => $payout->amount,
                'currency' => $payout->currency,
                'tds' => $payout->tds_amount,
                'status' => $payout->status->value,
                'paid_at' => $this->time($payout->paid_at),
                'transaction_reference' => $payout->status === PayoutStatus::Paid ? $payout->reference : null,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function notifications(User $user): array
    {
        return DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())
            ->orderBy('created_at')->get(['data', 'read_at', 'created_at'])
            ->map(function (object $row): array {
                $data = json_decode((string) $row->data, true) ?: [];

                return ['title' => $data['title'] ?? null, 'body' => $data['body'] ?? null, 'sent_at' => $row->created_at, 'read_at' => $row->read_at];
            })->all();
    }

    private function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
