<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * invoice_refs.status (spec 7.6, SCR-024): `draft -> sent -> paid`, and `sent -> overdue -> paid`. A daily job moves
 * `sent` to `overdue` once the due date has passed. There is no `cancelled` state; only a draft can be deleted.
 */
enum InvoiceStatus: string implements HasLabel
{
    use LabelTrait;

    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
    case Overdue = 'overdue';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent],
            self::Sent => [self::Paid, self::Overdue],
            self::Overdue => [self::Paid],
            self::Paid => [],
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
            self::Sent => 'info',
            self::Paid => 'success',
            self::Overdue => 'danger',
        };
    }
}
