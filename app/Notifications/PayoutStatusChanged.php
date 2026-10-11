<?php

namespace App\Notifications;

use App\Enums\PayoutStatus;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 14 "payout status change", to the contributor when a payout is approved, paid or cancelled. It names the project and
 * the new status only: no amount, no reference, so no money figures sit in email or in the notifications table (SCR-024).
 */
class PayoutStatusChanged extends GroibeNotification
{
    public function __construct(public string $projectTitle, public PayoutStatus $status) {}

    public function category(): string
    {
        return 'payout';
    }

    public function headline(): string
    {
        return match ($this->status) {
            PayoutStatus::Approved => "Payout approved for {$this->projectTitle}",
            PayoutStatus::Paid => "Payout paid for {$this->projectTitle}",
            PayoutStatus::Cancelled => "Payout cancelled for {$this->projectTitle}",
            PayoutStatus::Planned => "Payout planned for {$this->projectTitle}",
        };
    }

    public function url(): string
    {
        return route('contributor.payouts');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => 'Open Payouts to see the details.',
            'url' => $this->url(),
            'status' => $this->status->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.payout-status-changed', [
                'name' => $notifiable->name,
                'headline' => $this->headline(),
                'url' => $this->url(),
            ]);
    }
}
