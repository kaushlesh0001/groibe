<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.6. Direct assignment starts as `invited`; the contributor must accept. */
enum AssignmentStatus: string implements HasLabel
{
    use LabelTrait;

    case Invited = 'invited';
    case Active = 'active';
    case Completed = 'completed';
    case Declined = 'declined';
    case Removed = 'removed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Invited => [self::Active, self::Declined],
            self::Active => [self::Completed, self::Removed],
            self::Completed, self::Declined, self::Removed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** States in which a contributor already holds a seat on the project (spec 7.4 uniqueness). */
    public function isOpen(): bool
    {
        return in_array($this, self::openStates(), true);
    }

    /** @return list<self> */
    public static function openStates(): array
    {
        return [self::Invited, self::Active];
    }

    public function color(): string
    {
        return match ($this) {
            self::Invited => 'warning',
            self::Active => 'primary',
            self::Completed => 'success',
            self::Declined, self::Removed => 'danger',
        };
    }
}
