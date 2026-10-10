<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "task changed". Goes to the contributor the task is, or was, assigned to. */
class TaskChanged extends GroibeNotification
{
    public function __construct(public Task $task, public Project $project, public string $what) {}

    public function category(): string
    {
        return 'task';
    }

    public function headline(): string
    {
        return "Task updated on {$this->project->title}: {$this->task->title}";
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
            'body' => $this->what,
            'url' => $this->url(),
            'task_id' => $this->task->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.task-changed', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'taskTitle' => $this->task->title,
                'what' => $this->what,
                'url' => $this->url(),
            ]);
    }
}
