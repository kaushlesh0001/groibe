<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** open_roles.work_mode. Same keys as the profile work modes (remote, hybrid, onsite). */
enum WorkMode: string implements HasLabel
{
    use LabelTrait;

    case Remote = 'remote';
    case Hybrid = 'hybrid';
    case Onsite = 'onsite';

    public function getLabel(): string
    {
        return match ($this) {
            self::Remote => 'Remote',
            self::Hybrid => 'Hybrid',
            self::Onsite => 'On site',
        };
    }
}
