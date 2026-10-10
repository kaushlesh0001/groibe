<?php

namespace App\Notifications;

use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "deliverable shared". Goes to the client organization's owners and managers. */
class DeliverableShared extends GroibeNotification
{
    public function __construct(public Milestone $milestone, public Project $project) {}

    public function category(): string
    {
        return 'deliverable';
    }

    public function headline(): string
    {
        return "Ready for your review: {$this->milestone->title}";
    }

    public function url(): string
    {
        return route('client.deliverables');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->project->title,
            'url' => $this->url(),
            'milestone_id' => $this->milestone->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.deliverable-shared', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'milestoneTitle' => $this->milestone->title,
                'url' => $this->url(),
            ]);
    }
}
