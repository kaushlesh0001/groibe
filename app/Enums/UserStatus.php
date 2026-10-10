<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum UserStatus: string implements HasLabel
{
    use LabelTrait;

    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';
}
