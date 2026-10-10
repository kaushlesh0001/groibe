<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum CategoryType: string implements HasLabel
{
    use LabelTrait;

    case Skill = 'skill';
    case Industry = 'industry';
    case ProjectType = 'project_type';
}
