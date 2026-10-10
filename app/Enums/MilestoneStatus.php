<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * Spec 6.9. `shared` means visible to the client; only an admin can share.
 * `client_changes_requested` returns to `in_progress` with a change note.
 */
enum MilestoneStatus: string implements HasLabel
{
    use LabelTrait;

    case Planned = 'planned';
    case InProgress = 'in_progress';
    case InQa = 'in_qa';
    case Shared = 'shared';
    case ClientAccepted = 'client_accepted';
    case ClientChangesRequested = 'client_changes_requested';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::InProgress],
            self::InProgress => [self::InQa],
            self::InQa => [self::Shared, self::InProgress],
            self::Shared => [self::ClientAccepted, self::ClientChangesRequested],
            self::ClientChangesRequested => [self::InProgress],
            self::ClientAccepted => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Milestones the client may see: shared and everything after it. */
    public function isClientVisible(): bool
    {
        return in_array($this, [self::Shared, self::ClientAccepted, self::ClientChangesRequested], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'primary',
            self::InQa => 'info',
            self::Shared => 'warning',
            self::ClientAccepted => 'success',
            self::ClientChangesRequested => 'danger',
        };
    }
}
