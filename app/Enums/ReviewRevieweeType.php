<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** reviews.reviewee_type (spec 7.5). */
enum ReviewRevieweeType: string implements HasLabel
{
    use LabelTrait;

    case User = 'user';
    case Organization = 'organization';
}
