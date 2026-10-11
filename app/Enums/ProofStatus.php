<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * proof_of_work.status (spec 7.5, 9). `draft -> pending_approval -> published`, plus two Pass 6 decisions:
 * an admin can send a pending item back to `draft`, and can `hide` a published item and publish it again.
 */
enum ProofStatus: string implements HasLabel
{
    use LabelTrait;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Published = 'published';
    case Hidden = 'hidden';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval],
            self::PendingApproval => [self::Published, self::Draft],
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
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Published => 'success',
            self::Hidden => 'danger',
        };
    }
}
