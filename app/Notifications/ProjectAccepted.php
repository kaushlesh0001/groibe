<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The project moved to `accepted` (spec 6.2). Goes to the delivery side and to the contributors whose
 * assignments completed with it. Contributors see their own link; no client or rate detail is included.
 */
class ProjectAccepted extends GroibeNotification
{
    public function __construct(public Project $project) {}

    public function category(): string
    {
        return 'project';
    }

    public function headline(): string
    {
        return "Project accepted: {$this->project->title}";
    }

    public function url(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? ProjectResource::getUrl('edit', ['record' => $this->project])
            : route('contributor.work.project', $this->project);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $notifiable->isAdmin() ? 'The client accepted every milestone.' : 'The client accepted the work. Your assignment is complete.',
            'url' => $this->url($notifiable),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.project-accepted', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'forAdmin' => $notifiable->isAdmin(),
                'url' => $this->url($notifiable),
            ]);
    }
}
