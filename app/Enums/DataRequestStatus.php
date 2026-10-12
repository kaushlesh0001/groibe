<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * SCR-028. A request is `open` until a super admin takes it, `in_progress` while they work on it, and ends `completed` or
 * `rejected`. Both ends are final. A request can be rejected at any point before it is completed.
 */
enum DataRequestStatus: string implements HasColor, HasLabel
{
    use LabelTrait;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Rejected],
            self::InProgress => [self::Completed, self::Rejected],
            self::Completed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Still waiting for a decision. */
    public function isPending(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Completed => 'success',
            self::Rejected => 'gray',
        };
    }
}
