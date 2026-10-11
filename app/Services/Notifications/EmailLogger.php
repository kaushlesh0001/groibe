<?php

namespace App\Services\Notifications;

use App\Enums\EmailStatus;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the email log (spec 7.8, 14). Every message the app sends is recorded as sent through the mailer event, and a
 * queued email that fails for good is recorded as failed with a trimmed error. Only addresses, the template name and the
 * outcome are stored, never the body. Logging must never break sending, so every write swallows its own errors.
 */
class EmailLogger
{
    private const ERROR_LIMIT = 500;

    public function sent(MessageSent $event): void
    {
        $data = $event->data;
        $template = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? 'unknown';

        foreach ($event->message->getTo() as $address) {
            $this->write(class_basename((string) $template), $address->getAddress(), EmailStatus::Sent, null);
        }
    }

    /** A queued notification that ran out of tries. One row per recipient that was to get mail. */
    public function queuedNotificationFailed(JobFailed $event): void
    {
        if ($event->job->resolveQueuedJobClass() !== SendQueuedNotifications::class) {
            return;
        }

        try {
            $command = unserialize($event->job->payload()['data']['command']);
        } catch (Throwable) {
            return;
        }

        if (! $command instanceof SendQueuedNotifications) {
            return;
        }

        foreach (Collection::wrap($command->notifiables) as $notifiable) {
            // An empty channel list on the job means the notification's own via() decides.
            $channels = match (true) {
                $command->channels !== [] => $command->channels,
                method_exists($command->notification, 'via') => (array) $command->notification->via($notifiable),
                default => ['mail'],
            };

            if (! in_array('mail', $channels, true)) {
                continue;
            }

            $address = $notifiable->routeNotificationFor('mail', $command->notification);

            if (is_string($address)) {
                $this->write(class_basename($command->notification), $address, EmailStatus::Failed, $event->exception->getMessage());
            }
        }
    }

    /** For mail not sent through a notification, for example the contact inbox job. */
    public function failed(string $template, string $to, Throwable $error): void
    {
        $this->write($template, $to, EmailStatus::Failed, $error->getMessage());
    }

    private function write(string $template, string $to, EmailStatus $status, ?string $error): void
    {
        try {
            EmailLog::query()->create([
                'user_id' => User::query()->where('email', $to)->value('id'),
                'template' => $template,
                'to' => $to,
                'status' => $status,
                'error' => $error === null ? null : Str::limit($error, self::ERROR_LIMIT, ''),
                'sent_at' => $status === EmailStatus::Sent ? now() : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
