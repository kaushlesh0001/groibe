<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.1. */
enum BriefStatus: string implements HasLabel
{
    use LabelTrait;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case NeedsInfo = 'needs_info';
    case Scoped = 'scoped';
    case Converted = 'converted';
    case Declined = 'declined';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::InReview],
            self::InReview => [self::NeedsInfo, self::Scoped, self::Declined],
            self::NeedsInfo => [self::InReview],
            self::Scoped => [self::Converted],
            self::Converted, self::Declined => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Spec 6.1: the client can edit only in draft and needs_info. */
    public function isClientEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsInfo], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted, self::InReview => 'info',
            self::NeedsInfo => 'warning',
            self::Scoped, self::Converted => 'success',
            self::Declined => 'danger',
        };
    }
}
