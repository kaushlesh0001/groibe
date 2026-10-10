<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** open_roles.compensation_type (spec 7.4). */
enum CompensationType: string implements HasLabel
{
    use LabelTrait;

    case Paid = 'paid';
    case Stipend = 'stipend';
    case UnpaidLearning = 'unpaid_learning';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Stipend => 'Stipend',
            self::UnpaidLearning => 'Unpaid learning',
            self::Other => 'Other',
        };
    }
}
