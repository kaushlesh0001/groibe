<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** projects.showcase_consent (spec 7.3). Consent flows are built in Pass 6. */
enum ShowcaseConsent: string implements HasLabel
{
    use LabelTrait;

    case None = 'none';
    case Anonymous = 'anonymous';
    case Named = 'named';
}
