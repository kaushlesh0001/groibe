<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * payouts.status (spec 7.6, SCR-024): `planned -> approved -> paid`, and `planned | approved -> cancelled`.
 * `paid` and `cancelled` are final. A correction to an approved payout is a cancel plus a new payout.
 */
enum PayoutStatus: string implements HasLabel
{
    use LabelTrait;

    case Planned = 'planned';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::Approved, self::Cancelled],
            self::Approved => [self::Paid, self::Cancelled],
            self::Paid, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Statuses that count against the agreed amount of a seat. */
    public function countsTowardsAgreedAmount(): bool
    {
        return $this !== self::Cancelled;
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
