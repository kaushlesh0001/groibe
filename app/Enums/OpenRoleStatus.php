<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 6.4. `draft -> published -> closed | filled | archived`. */
enum OpenRoleStatus: string implements HasLabel
{
    use LabelTrait;

    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Filled = 'filled';
    case Archived = 'archived';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published],
            self::Published => [self::Closed, self::Filled, self::Archived],
            self::Closed, self::Filled, self::Archived => [],
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
            self::Published => 'success',
            self::Closed => 'warning',
            self::Filled => 'primary',
            self::Archived => 'gray',
        };
    }
}
