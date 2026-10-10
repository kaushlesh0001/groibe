<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "assignment invite" outcome. Goes to the delivery side when a contributor accepts or declines. */
class AssignmentResponded extends GroibeNotification
{
    public function __construct(
        public Assignment $assignment,
        public Project $project,
        public string $contributorName,
        public bool $accepted,
    ) {}

    public function category(): string
    {
        return 'assignment';
    }

    public function headline(): string
    {
        $verb = $this->accepted ? 'accepted' : 'declined';

        return "{$this->contributorName} {$verb} the invite for {$this->project->title}";
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
            'body' => "Role: {$this->assignment->role_title}",
            'url' => $this->url(),
            'assignment_id' => $this->assignment->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.assignment-responded', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'roleTitle' => $this->assignment->role_title,
                'url' => $this->url(),
            ]);
    }
}
