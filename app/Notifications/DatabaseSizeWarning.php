<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Daily warning to super admins while the database is over the warning level of the host plan's limit (SCR-034). It goes
 * in the `security` category on purpose: that category is locked on, so a capacity alarm cannot be switched off by accident.
 */
class DatabaseSizeWarning extends GroibeNotification
{
    public function __construct(public float $megabytes, public int $limitMb, public int $percent) {}

    public function category(): string
    {
        return 'security';
    }

    public function headline(): string
    {
        return "The database is at {$this->percent}% of its limit";
    }

    public function url(): string
    {
        return url('/admin');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => number_format($this->megabytes, 1)." MB used of {$this->limitMb} MB. Free space or raise the limit before the host stops writes.",
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.database-size-warning', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'megabytes' => number_format($this->megabytes, 1),
                'limit' => $this->limitMb,
                'url' => $this->url(),
            ]);
    }
}
