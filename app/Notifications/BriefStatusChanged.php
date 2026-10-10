<?php

namespace App\Notifications;

use App\Enums\BriefStatus;
use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Models\Brief;
use Illuminate\Notifications\Messages\MailMessage;

/** Spec 14 "Brief status changes". Sent to the client side on review outcomes and to admins on submit and reply. */
class BriefStatusChanged extends GroibeNotification
{
    public function __construct(
        public Brief $brief,
        public BriefStatus $to,
        public ?string $note = null,
        public bool $forAdmins = false,
    ) {}

    public function category(): string
    {
        return 'brief';
    }

    public function headline(): string
    {
        if ($this->forAdmins) {
            return $this->to === BriefStatus::Submitted
                ? "New brief submitted: {$this->brief->title}"
                : "Client replied on brief: {$this->brief->title}";
        }

        return match ($this->to) {
            BriefStatus::Submitted => "We received your brief: {$this->brief->title}",
            BriefStatus::InReview => "Your brief is in review: {$this->brief->title}",
            BriefStatus::NeedsInfo => "We need more information on your brief: {$this->brief->title}",
            BriefStatus::Scoped => "Your brief has been scoped: {$this->brief->title}",
            BriefStatus::Converted => "Your brief is now being set up as a project: {$this->brief->title}",
            BriefStatus::Declined => "Update on your brief: {$this->brief->title}",
            BriefStatus::Draft => "Your brief was saved: {$this->brief->title}",
        };
    }

    public function url(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? BriefResource::getUrl('edit', ['record' => $this->brief])
            : route('client.briefs.show', $this->brief);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->note,
            'url' => $this->url($notifiable),
            'brief_uuid' => $this->brief->uuid,
            'status' => $this->to->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.brief-status', [
                'headline' => $this->headline(),
                'note' => $this->note,
                'url' => $this->url($notifiable),
                'name' => $notifiable->name,
            ]);
    }
}
