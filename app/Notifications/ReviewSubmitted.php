<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Models\Review;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 9, 13: a review is waiting in the moderation queue. Goes to admins who can moderate. Carries no review text or ratings. */
class ReviewSubmitted extends GroibeNotification
{
    public function __construct(public Review $review) {}

    public function category(): string
    {
        return 'review';
    }

    public function headline(): string
    {
        return 'A review is waiting for moderation';
    }

    public function url(): string
    {
        return ReviewResource::getUrl('index');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => 'Publish or hide it from the moderation queue.',
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.review-submitted', [
                'name' => $notifiable->name,
                'url' => $this->url(),
            ]);
    }
}
