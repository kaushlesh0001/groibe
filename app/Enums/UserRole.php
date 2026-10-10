<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    use LabelTrait;

    case Admin = 'admin';
    case Contributor = 'contributor';
    case Client = 'client';
}
