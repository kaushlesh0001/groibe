<?php

namespace App\Notifications;

use App\Models\OpenRole;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "application status" family: a contributor is added to the invite list of a live invite-only role (SCR-018). */
class OpenRoleInvited extends GroibeNotification
{
    public function __construct(public OpenRole $role) {}

    public function category(): string
    {
        return 'application';
    }

    public function headline(): string
    {
        return "You are invited to apply for {$this->role->title}";
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
            'body' => $this->role->summary,
            'url' => $this->url(),
            'open_role_id' => $this->role->getKey(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.open-role-invited', [
                'name' => $notifiable->name,
                'roleTitle' => $this->role->title,
                'url' => $this->url(),
            ]);
    }
}
