<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum SettingType: string implements HasLabel
{
    use LabelTrait;

    case String = 'string';
    case Integer = 'int';
    case Boolean = 'bool';
    case Json = 'json';
}
