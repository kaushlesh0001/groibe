<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** faqs.audience (spec 7.9, 10.1). The homepage and /faq show these as tabs. */
enum FaqAudience: string implements HasLabel
{
    use LabelTrait;

    case General = 'general';
    case Contributor = 'contributor';
    case Client = 'client';
}
