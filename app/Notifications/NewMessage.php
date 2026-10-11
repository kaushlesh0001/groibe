<?php

namespace App\Notifications;

use App\Models\Thread;
use App\Services\Messaging\ThreadLinks;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 14 "new message". Deliberately carries no message text and no author: the notification is stored and emailed, and
 * only the thread itself decides who may read what. Recipients are chosen by ThreadAudience, so an internal message
 * never reaches a client or contributor.
 */
class NewMessage extends GroibeNotification
{
    public function __construct(public string $threadUuid, public string $subject) {}

    public static function for(Thread $thread): self
    {
        return new self($thread->uuid, $thread->subject);
    }

    public function category(): string
    {
        return 'message';
    }

    public function headline(): string
    {
        return "New message: {$this->subject}";
    }

    private function url(object $notifiable): string
    {
        return app(ThreadLinks::class)->for(Thread::query()->where('uuid', $this->threadUuid)->firstOrFail(), $notifiable);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => 'Open the conversation to read it.',
            'url' => $this->url($notifiable),
            'thread_uuid' => $this->threadUuid,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.new-message', [
                'name' => $notifiable->name,
                'subject' => $this->subject,
                'url' => $this->url($notifiable),
            ]);
    }
}
