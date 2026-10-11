<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** featured_items.type (spec 7.9). Names the table `item_id` points at. */
enum FeaturedItemType: string implements HasLabel
{
    use LabelTrait;

    case OpenRole = 'open_role';
    case Contributor = 'contributor';
    case Organization = 'organization';
    case ProofOfWork = 'proof_of_work';
    case Testimonial = 'testimonial';
    case Project = 'project';
}
