<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The project moved to `closed` (spec 6.2, 9). The client side is asked to review the team and to give showcase
 * consent; contributors are asked to review the client and to finish their proof-of-work drafts. No ratings,
 * rates or other people's drafts are included.
 */
class ProjectClosed extends GroibeNotification
{
    public function __construct(public Project $project, public string $audience) {}

    public function category(): string
    {
        return 'project';
    }

    public function headline(): string
    {
        return "Project closed: {$this->project->title}";
    }

    public function url(): string
    {
        return $this->audience === 'client'
            ? route('client.projects.reviews', $this->project)
            : route('contributor.portfolio');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->audience === 'client'
                ? 'Please review the team and choose how this project may be shown.'
                : 'Your proof-of-work draft is ready, and you can review the client.',
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.project-closed', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'forClient' => $this->audience === 'client',
                'url' => $this->url(),
            ]);
    }
}
