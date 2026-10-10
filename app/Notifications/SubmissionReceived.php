<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Models\Project;
use App\Models\Submission;
use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "submission". Goes to the delivery side when a contributor submits work for QA. */
class SubmissionReceived extends GroibeNotification
{
    public function __construct(
        public Submission $submission,
        public Task $task,
        public Project $project,
        public string $contributorName,
    ) {}

    public function category(): string
    {
        return 'submission';
    }

    public function headline(): string
    {
        return "Work submitted for QA: {$this->task->title}";
    }

    public function url(): string
    {
        return SubmissionResource::getUrl('index');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => "{$this->contributorName}, version {$this->submission->version}, {$this->project->title}",
            'url' => $this->url(),
            'submission_id' => $this->submission->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.submission-received', [
                'name' => $notifiable->name,
                'taskTitle' => $this->task->title,
                'projectTitle' => $this->project->title,
                'contributorName' => $this->contributorName,
                'version' => $this->submission->version,
                'url' => $this->url(),
            ]);
    }
}
