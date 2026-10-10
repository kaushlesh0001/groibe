<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Daily digest for one project: open tasks past their due date (spec 6.7, Pass 4 item 9). Goes to the delivery manager. */
class TasksOverdue extends GroibeNotification
{
    /**
     * @param  list<array{title: string, due: string, status: string, assignee: string}>  $tasks  the most overdue first, capped by the command
     */
    public function __construct(public Project $project, public int $total, public array $tasks) {}

    public function category(): string
    {
        return 'task';
    }

    public function headline(): string
    {
        $noun = $this->total === 1 ? 'task is' : 'tasks are';

        return "{$this->total} {$noun} overdue on {$this->project->title}";
    }

    public function url(): string
    {
        return ProjectResource::getUrl('workspace', ['record' => $this->project]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => collect($this->tasks)->take(3)->pluck('title')->join(', '),
            'url' => $this->url(),
            'overdue_count' => $this->total,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.tasks-overdue', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'total' => $this->total,
                'tasks' => $this->tasks,
                'url' => $this->url(),
            ]);
    }
}
