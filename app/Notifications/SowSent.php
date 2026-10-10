<?php

namespace App\Notifications;

use App\Models\Sow;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "SOW sent". Goes to the client organization's owners and managers. */
class SowSent extends GroibeNotification
{
    public function __construct(public Sow $sow, public string $projectTitle, public string $projectUuid) {}

    public function category(): string
    {
        return 'sow';
    }

    public function headline(): string
    {
        return "Statement of Work ready for your review: {$this->projectTitle}";
    }

    public function url(): string
    {
        return route('client.projects.show', $this->projectUuid);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => "Version {$this->sow->version}",
            'url' => $this->url(),
            'sow_version' => $this->sow->version,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.sow-sent', [
                'name' => $notifiable->name,
                'projectTitle' => $this->projectTitle,
                'version' => $this->sow->version,
                'url' => $this->url(),
            ]);
    }
}
