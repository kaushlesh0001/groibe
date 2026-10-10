<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "deliverable accepted/changes requested". Goes to the delivery side with the client's note. */
class MilestoneResponded extends GroibeNotification
{
    public function __construct(
        public Milestone $milestone,
        public Project $project,
        public string $clientName,
        public bool $accepted,
    ) {}

    public function category(): string
    {
        return 'deliverable';
    }

    public function headline(): string
    {
        $verb = $this->accepted ? 'accepted' : 'asked for changes on';

        return "{$this->clientName} {$verb} {$this->milestone->title}";
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
            'body' => $this->accepted ? $this->project->title : (string) $this->milestone->client_note,
            'url' => $this->url(),
            'milestone_id' => $this->milestone->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.milestone-responded', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'projectTitle' => $this->project->title,
                'note' => $this->accepted ? null : $this->milestone->client_note,
                'url' => $this->url(),
            ]);
    }
}
