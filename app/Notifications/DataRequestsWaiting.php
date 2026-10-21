<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\DataRequests\DataRequestResource;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Daily reminder to super admins while data requests have waited longer than the reminder period (SCR-039). It carries the
 * count and the age of the oldest only, never a name or what was asked. It is in the locked `security` category: a request
 * about a person's own data must not go quiet because someone switched a category off.
 */
class DataRequestsWaiting extends GroibeNotification
{
    public function __construct(public int $total, public int $oldestDays, public int $reminderDays) {}

    public function category(): string
    {
        return 'security';
    }

    public function headline(): string
    {
        return $this->total === 1
            ? 'A data request has waited more than '.$this->reminderDays.' days'
            : "{$this->total} data requests have waited more than {$this->reminderDays} days";
    }

    public function url(): string
    {
        return DataRequestResource::getUrl('index');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => "The oldest has waited {$this->oldestDays} days. Open System, Data requests.",
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.data-requests-waiting', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'oldestDays' => $this->oldestDays,
                'url' => $this->url(),
            ]);
    }
}
