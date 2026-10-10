<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "submission QA result". Goes to the contributor who submitted. The reviewer note is part of it. */
class SubmissionReviewed extends GroibeNotification
{
    public function __construct(
        public Submission $submission,
        public Task $task,
        public Project $project,
        public bool $approved,
    ) {}

    public function category(): string
    {
        return 'submission';
    }

    public function headline(): string
    {
        return $this->approved
            ? "Your work was approved: {$this->task->title}"
            : "Changes requested: {$this->task->title}";
    }

    public function url(): string
    {
        return route('contributor.work.task', $this->task);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->submission->qa_note ?? "Version {$this->submission->version} on {$this->project->title}",
            'url' => $this->url(),
            'submission_id' => $this->submission->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.submission-reviewed', [
                'name' => $notifiable->name,
                'taskTitle' => $this->task->title,
                'projectTitle' => $this->project->title,
                'version' => $this->submission->version,
                'approved' => $this->approved,
                'note' => $this->submission->qa_note,
                'url' => $this->url(),
            ]);
    }
}
