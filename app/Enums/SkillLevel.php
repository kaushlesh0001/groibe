<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum SkillLevel: string implements HasLabel
{
    use LabelTrait;

    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Expert = 'expert';
}
