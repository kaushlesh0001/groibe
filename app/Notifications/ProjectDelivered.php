<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** The project moved to `delivered` (spec 6.2). Goes to the client organization's owners and managers. */
class ProjectDelivered extends GroibeNotification
{
    public function __construct(public Project $project) {}

    public function category(): string
    {
        return 'project';
    }

    public function headline(): string
    {
        return "Your project has been delivered: {$this->project->title}";
    }

    public function url(): string
    {
        return route('client.projects.show', $this->project);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => 'Every milestone is ready for your review.',
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.project-delivered', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'url' => $this->url(),
            ]);
    }
}
