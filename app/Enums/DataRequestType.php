<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** What a person asks for (spec 17.2, SCR-028): a copy of their data, or the erasure of it. */
enum DataRequestType: string implements HasLabel
{
    use LabelTrait;

    case Export = 'export';
    case Deletion = 'deletion';

    /** How the person who asked would say it. */
    public function describe(): string
    {
        return match ($this) {
            self::Export => 'Copy of my data',
            self::Deletion => 'Erase my data',
        };
    }
}
