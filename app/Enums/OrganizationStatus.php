<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum OrganizationStatus: string implements HasLabel
{
    use LabelTrait;

    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
}
