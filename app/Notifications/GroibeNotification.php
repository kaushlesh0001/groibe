<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for platform notifications (spec 14): database plus mail, queued. The NotificationDispatcher
 * sets `channels` per recipient from their category preferences.
 */
abstract class GroibeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public array $channels = ['database', 'mail'];

    /** One of config('groibe.notifications.categories'). */
    abstract public function category(): string;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }
}
