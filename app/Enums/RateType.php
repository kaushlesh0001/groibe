<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 7.4 `assignments.rate_type`. */
enum RateType: string implements HasLabel
{
    use LabelTrait;

    case Fixed = 'fixed';
    case Hourly = 'hourly';
}
