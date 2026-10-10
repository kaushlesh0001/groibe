<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "task assigned". Goes to the contributor who now owns the task. */
class TaskAssigned extends GroibeNotification
{
    public function __construct(public Task $task, public Project $project) {}

    public function category(): string
    {
        return 'task';
    }

    public function headline(): string
    {
        return "New task on {$this->project->title}: {$this->task->title}";
    }

    public function url(): string
    {
        return route('contributor.dashboard');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->task->due_date ? 'Due '.$this->task->due_date->toFormattedDateString() : 'No due date set',
            'url' => $this->url(),
            'task_id' => $this->task->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.task-assigned', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'taskTitle' => $this->task->title,
                'due' => $this->task->due_date?->toFormattedDateString(),
                'url' => $this->url(),
            ]);
    }
}
