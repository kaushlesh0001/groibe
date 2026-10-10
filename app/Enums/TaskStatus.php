<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.7. `approved` is set by an admin after internal QA. */
enum TaskStatus: string implements HasLabel
{
    use LabelTrait;

    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress],
            self::InProgress => [self::Submitted],
            self::Submitted => [self::Approved, self::ChangesRequested],
            self::ChangesRequested => [self::InProgress],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'primary',
            self::Submitted => 'info',
            self::Approved => 'success',
            self::ChangesRequested => 'warning',
        };
    }
}
