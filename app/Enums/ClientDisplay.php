<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** proof_of_work.client_display (spec 7.5, 9). Follows the project's showcase consent. */
enum ClientDisplay: string implements HasLabel
{
    use LabelTrait;

    case Anonymous = 'anonymous';
    case Named = 'named';
}
