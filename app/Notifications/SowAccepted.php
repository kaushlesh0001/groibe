<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\Sow;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "SOW accepted". Goes to the delivery side and the client's owners and managers. */
class SowAccepted extends GroibeNotification
{
    public function __construct(public Sow $sow, public Project $project, public string $acceptedByName) {}

    public function category(): string
    {
        return 'sow';
    }

    public function headline(): string
    {
        return "Statement of Work accepted: {$this->project->title}";
    }

    public function url(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? ProjectResource::getUrl('edit', ['record' => $this->project])
            : route('client.projects.show', $this->project);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => "Version {$this->sow->version} accepted by {$this->acceptedByName}",
            'url' => $this->url($notifiable),
            'sow_version' => $this->sow->version,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.sow-accepted', [
                'name' => $notifiable->name,
                'projectTitle' => $this->project->title,
                'version' => $this->sow->version,
                'acceptedBy' => $this->acceptedByName,
                'url' => $this->url($notifiable),
            ]);
    }
}
