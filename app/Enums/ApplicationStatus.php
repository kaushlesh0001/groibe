<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * Spec 6.5: `submitted -> shortlisted -> offered -> accepted`; `submitted|shortlisted|offered -> rejected`;
 * `submitted|shortlisted -> withdrawn`; `offered -> declined`. SCR-019 (provisional) adds `withdrawn -> submitted`.
 */
enum ApplicationStatus: string implements HasLabel
{
    use LabelTrait;

    case Submitted = 'submitted';
    case Shortlisted = 'shortlisted';
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Declined = 'declined';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::Shortlisted, self::Rejected, self::Withdrawn],
            self::Shortlisted => [self::Offered, self::Rejected, self::Withdrawn],
            self::Offered => [self::Accepted, self::Rejected, self::Declined],
            self::Withdrawn => [self::Submitted],
            self::Accepted, self::Rejected, self::Declined => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Still in play: an admin may act on it or the contributor may withdraw. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::Shortlisted, self::Offered], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::Shortlisted => 'info',
            self::Offered => 'warning',
            self::Accepted => 'success',
            self::Rejected, self::Declined => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
