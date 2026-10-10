<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Applications\ApplicationResource;
use App\Models\Application;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 14 "application status". One class for every application event: the contributor hears about shortlisting,
 * an offer and a rejection; the admin side hears about new applications, withdrawals and answers to an offer.
 * Admin notes and other applicants never appear in the message.
 */
class ApplicationUpdated extends GroibeNotification
{
    public function __construct(public Application $application, public string $event, public bool $toAdmin = false) {}

    public function category(): string
    {
        return 'application';
    }

    public function headline(): string
    {
        $role = $this->application->openRole->title;
        $who = $this->application->user->name;

        return match ($this->event) {
            'received' => "New application from {$who} for {$role}",
            'withdrawn' => "{$who} withdrew the application for {$role}",
            'accepted' => "{$who} accepted the offer for {$role}",
            'declined' => "{$who} declined the offer for {$role}",
            'shortlisted' => "You have been shortlisted for {$role}",
            'offered' => "You have an offer for {$role}",
            default => "Update on your application for {$role}",
        };
    }

    public function url(): string
    {
        return $this->toAdmin
            ? ApplicationResource::getUrl('view', ['record' => $this->application])
            : route('contributor.dashboard');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->application->openRole->title,
            'url' => $this->url(),
            'application_id' => $this->application->getKey(),
            'event' => $this->event,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.application-updated', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'roleTitle' => $this->application->openRole->title,
                'event' => $this->event,
                'url' => $this->url(),
            ]);
    }
}
