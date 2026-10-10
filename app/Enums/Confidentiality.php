<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum Confidentiality: string implements HasLabel
{
    use LabelTrait;

    case Standard = 'standard';
    case Confidential = 'confidential';
}
