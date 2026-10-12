<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * SCR-027. A file is uploaded, mapped and dry-run to `validated`, then queued, run in chunks, and ends
 * `completed` or `failed`. Changing the mapping after a dry run sends it back to `uploaded`, so a result
 * on screen always belongs to the mapping that produced it.
 */
enum ImportStatus: string implements HasColor, HasLabel
{
    use LabelTrait;

    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Uploaded => [self::Validated, self::Failed],
            self::Validated => [self::Validated, self::Uploaded, self::Queued, self::Failed],
            self::Queued => [self::Running, self::Failed],
            self::Running => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uploaded => 'gray',
            self::Validated => 'info',
            self::Queued, self::Running => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
