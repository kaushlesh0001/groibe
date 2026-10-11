<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** reviews.direction (spec 7.5, 9). */
enum ReviewDirection: string implements HasLabel
{
    use LabelTrait;

    case ClientToContributor = 'client_to_contributor';
    case ContributorToClient = 'contributor_to_client';
    case AdminToContributor = 'admin_to_contributor';

    public function revieweeType(): ReviewRevieweeType
    {
        return $this === self::ContributorToClient ? ReviewRevieweeType::Organization : ReviewRevieweeType::User;
    }

    /** @return list<string> rating columns that apply to this direction, each 1 to 5. */
    public function criteria(): array
    {
        return match ($this) {
            self::ContributorToClient => ['clarity', 'communication', 'professionalism', 'feedback_time', 'fairness'],
            default => ['quality', 'communication', 'reliability', 'timeliness'],
        };
    }
}
