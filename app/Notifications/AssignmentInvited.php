<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "assignment invite". Goes to the invited contributor. Rates stay out of the message. */
class AssignmentInvited extends GroibeNotification
{
    public function __construct(public Assignment $assignment, public Project $project) {}

    public function category(): string
    {
        return 'assignment';
    }

    public function headline(): string
    {
        return "You are invited to join {$this->project->title}";
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
            'body' => "Role: {$this->assignment->role_title}",
            'url' => $this->url(),
            'assignment_id' => $this->assignment->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.assignment-invited', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'roleTitle' => $this->assignment->role_title,
                'url' => $this->url(),
            ]);
    }
}
