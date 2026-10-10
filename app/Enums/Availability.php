<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum Availability: string implements HasLabel
{
    use LabelTrait;

    case Available = 'available';
    case Limited = 'limited';
    case Unavailable = 'unavailable';
}
