<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum OrgRole: string implements HasLabel
{
    use LabelTrait;

    case Owner = 'owner';
    case Manager = 'manager';
    case Viewer = 'viewer';
}
