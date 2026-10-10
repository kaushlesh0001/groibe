<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 6.2 removal. Goes to the removed contributor and to the delivery manager. The internal removal
 * reason is shown to admins only.
 */
class AssignmentRemoved extends GroibeNotification
{
    public function __construct(
        public Assignment $assignment,
        public Project $project,
        public string $contributorName,
        public int $tasksReturned,
    ) {}

    public function category(): string
    {
        return 'assignment';
    }

    public function headline(): string
    {
        return "{$this->contributorName} was removed from {$this->project->title}";
    }

    public function url(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? ProjectResource::getUrl('edit', ['record' => $this->project])
            : route('contributor.dashboard');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url($notifiable),
            'assignment_id' => $this->assignment->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->markdown('emails.assignment-removed', [
                'name' => $notifiable->name,
                'headline' => $this->title($notifiable),
                'body' => $this->body($notifiable),
                'url' => $this->url($notifiable),
            ]);
    }

    private function title(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? $this->headline()
            : "Your assignment on {$this->project->title} has ended";
    }

    private function body(object $notifiable): string
    {
        if (! $notifiable->isAdmin()) {
            return 'Your unfinished tasks on this project have been returned to the team.';
        }

        return "{$this->tasksReturned} unfinished task(s) returned to unassigned. Reason: {$this->assignment->removed_reason}";
    }
}
