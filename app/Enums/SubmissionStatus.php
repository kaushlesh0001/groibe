<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.8. Only `qa_approved` submissions can attach to a deliverable. */
enum SubmissionStatus: string implements HasLabel
{
    use LabelTrait;

    case Submitted = 'submitted';
    case QaChangesRequested = 'qa_changes_requested';
    case QaApproved = 'qa_approved';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::QaChangesRequested, self::QaApproved],
            self::QaChangesRequested, self::QaApproved => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'info',
            self::QaChangesRequested => 'warning',
            self::QaApproved => 'success',
        };
    }
}
