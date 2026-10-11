<?php

namespace App\Notifications;

use App\Filament\Admin\Resources\ProofOfWork\ProofOfWorkResource;
use App\Models\ProofOfWork;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 9, 14. One class for the proof-of-work events: an admin hears that an item awaits approval; the contributor
 * hears that it was published, sent back for edits (with the admin note) or hidden.
 */
class ProofOfWorkChanged extends GroibeNotification
{
    public function __construct(public ProofOfWork $proof, public string $event, public ?string $note = null, public bool $toAdmin = false) {}

    public function category(): string
    {
        return 'portfolio';
    }

    public function headline(): string
    {
        return match ($this->event) {
            'submitted' => "Proof of work awaiting approval: {$this->proof->title}",
            'published' => "Your proof of work is published: {$this->proof->title}",
            'returned' => "Edits needed on your proof of work: {$this->proof->title}",
            'hidden' => "Your proof of work was hidden: {$this->proof->title}",
            default => "Update on your proof of work: {$this->proof->title}",
        };
    }

    public function url(): string
    {
        return $this->toAdmin
            ? ProofOfWorkResource::getUrl('view', ['record' => $this->proof])
            : route('contributor.portfolio.edit', $this->proof);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->note,
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.proof-of-work-changed', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'event' => $this->event,
                'note' => $this->note,
                'url' => $this->url(),
            ]);
    }
}
