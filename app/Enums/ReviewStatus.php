<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** reviews.status (spec 7.5, 9). Every review is moderated: `pending_moderation -> published | hidden`, and a moderator can flip later. */
enum ReviewStatus: string implements HasLabel
{
    use LabelTrait;

    case PendingModeration = 'pending_moderation';
    case Published = 'published';
    case Hidden = 'hidden';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingModeration => [self::Published, self::Hidden],
            self::Published => [self::Hidden],
            self::Hidden => [self::Published],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingModeration => 'warning',
            self::Published => 'success',
            self::Hidden => 'danger',
        };
    }
}
