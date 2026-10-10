<?php

namespace App\Notifications;

use App\Enums\OnboardingState;
use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Models\Profile;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Spec 14 "onboarding status change". Sent to the contributor on review outcomes and to
 * community admins when a profile is submitted. Reasons stay in the activity log, never in the message.
 */
class ContributorStatusChanged extends GroibeNotification
{
    public function __construct(
        public Profile $profile,
        public OnboardingState $to,
        public bool $forAdmins = false,
        public bool $reinstated = false,
    ) {}

    public function category(): string
    {
        return 'onboarding';
    }

    public function headline(): string
    {
        if ($this->forAdmins) {
            return 'A contributor profile is waiting for review';
        }

        return match ($this->to) {
            OnboardingState::PendingReview => 'We received your profile and will review it soon',
            OnboardingState::Active => $this->reinstated ? 'Your Groibe account is active again' : 'Welcome to Groibe, your profile is approved',
            OnboardingState::Rejected => 'Update on your Groibe application',
            OnboardingState::Suspended => 'Your Groibe account has been suspended',
            default => 'Your Groibe profile status changed',
        };
    }

    public function body(): string
    {
        if ($this->forAdmins) {
            return 'Open the review queue to approve or reject it.';
        }

        return match ($this->to) {
            OnboardingState::PendingReview => 'You will hear from us once the review is done.',
            OnboardingState::Active => 'You can now be considered for projects and opportunities.',
            OnboardingState::Rejected => 'We are not able to approve your profile at this time. Contact us if you would like to know more.',
            OnboardingState::Suspended => 'Contact us if you think this is a mistake.',
            default => '',
        };
    }

    public function url(object $notifiable): string
    {
        return $notifiable->isAdmin()
            ? ContributorResource::getUrl('view', ['record' => $this->profile])
            : route('contributor.dashboard');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->headline(),
            'body' => $this->body(),
            'url' => $this->url($notifiable),
            'status' => $this->to->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->markdown('emails.contributor-status', [
                'headline' => $this->headline(),
                'body' => $this->body(),
                'url' => $this->url($notifiable),
                'name' => $notifiable->name,
                'buttonLabel' => $this->forAdmins ? 'Open review queue' : 'Open your dashboard',
            ]);
    }
}
