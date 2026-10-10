<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.2. */
enum ProjectStatus: string implements HasLabel
{
    use LabelTrait;

    case Draft = 'draft';
    case SowSent = 'sow_sent';
    case SowAccepted = 'sow_accepted';
    case Staffing = 'staffing';
    case Active = 'active';
    case InQa = 'in_qa';
    case Delivered = 'delivered';
    case Accepted = 'accepted';
    case Closed = 'closed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    /**
     * on_hold resumes to the state it was held from (SCR-008); ResumeProject picks the target.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::SowSent, self::Cancelled],
            self::SowSent => [self::SowAccepted, self::Cancelled],
            self::SowAccepted => [self::Staffing, self::Cancelled],
            self::Staffing => [self::Active, self::OnHold, self::Cancelled],
            self::Active => [self::InQa, self::OnHold, self::Cancelled],
            self::InQa => [self::Delivered, self::Cancelled],
            self::Delivered => [self::Accepted, self::Cancelled],
            self::Accepted => [self::Closed, self::Cancelled],
            self::OnHold => [self::Staffing, self::Active, self::Cancelled],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::SowSent, self::SowAccepted => 'info',
            self::Staffing, self::OnHold => 'warning',
            self::Active, self::InQa => 'primary',
            self::Delivered, self::Accepted, self::Closed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
