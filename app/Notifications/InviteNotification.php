<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) config('groibe.invites.expire_days');

        return (new MailMessage)
            ->subject('Activate your Groibe account')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you on Groibe. Use the button below to set your password.')
            ->action('Activate account', $this->url)
            ->line("This link expires in {$days} days. If it has expired, ask the Groibe team to send a new one.");
    }
}
