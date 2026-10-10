<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Every task on the milestone is submitted or approved, so it moved to QA (spec 6.9). Goes to the delivery side. */
class MilestoneReadyToShare extends GroibeNotification
{
    public function __construct(public Milestone $milestone, public Project $project) {}

    public function category(): string
    {
        return 'deliverable';
    }

    public function headline(): string
    {
        return "Milestone in QA: {$this->milestone->title}";
    }

    public function url(): string
    {
        return ProjectResource::getUrl('edit', ['record' => $this->project]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => "{$this->project->title}: approve the remaining work, build the deliverable and share it.",
            'url' => $this->url(),
            'milestone_id' => $this->milestone->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.milestone-ready-to-share', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'milestoneTitle' => $this->milestone->title,
                'url' => $this->url(),
            ]);
    }
}
