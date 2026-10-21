<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The emailed sign-in code for the admin panel (spec 17.2, SCR-040). It is deliberately not queued: a queued notification would
 * write the code into the `jobs` table, and a failed one into `failed_jobs`, and the code must not live anywhere but the email
 * and the hashed copy in the cache. It does not go through `NotificationDispatcher`, so no preference can switch it off, and it
 * is not a `GroibeNotification`, so nothing writes it to the bell. The code is a `SensitiveParameter`, which keeps it out of
 * stack traces. The email log records this class name and the address, never the body.
 */
class AdminSignInCode extends Notification
{
    public function __construct(
        #[SensitiveParameter]
        public string $code,
        public int $codeExpiryMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Groibe admin sign-in code')
            ->markdown('emails.admin-sign-in-code', [
                'name' => $notifiable->name,
                'code' => $this->code,
                'minutes' => $this->codeExpiryMinutes,
            ]);
    }
}
