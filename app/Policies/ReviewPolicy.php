<?php

namespace App\Policies;

use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationAccess;

/**
 * Spec 5 "Review" and spec 9. Admin reads and moderates (delivery_manager RUA, community_manager R). A contributor reads
 * the published client reviews written about them ("R about self"), never reviews of others, never internal admin
 * reviews (Pass 6 decision). A client or contributor reads the review they wrote. Org-directed reviews are admin-only unless `public_client_reviews` is on, and that flag
 * only ever opens the public surface, never this policy.
 *
 * Writing is a project-level ability: see ProjectPolicy::reviewContributors() and reviewClient(), and the Review Actions.
 */
class ReviewPolicy
{
    use ChecksOrganizationAccess;

    public function viewAny(User $actor): bool
    {
        return $actor->isContributor() || $actor->isClient() || $this->adminCan($actor, 'reviews.view');
    }

    public function view(User $actor, Review $review): bool
    {
        if ($this->adminCan($actor, 'reviews.view')) {
            return true;
        }

        if ($review->reviewer_id === $actor->getKey()) {
            return true;
        }

        return $actor->isContributor()
            && $review->direction === ReviewDirection::ClientToContributor
            && $review->status === ReviewStatus::Published
            && $review->reviewee_id === $actor->getKey();
    }

    /** Internal reviews need the create permission; the matrix gives it to super admin only (Pass 6 decision). */
    public function create(User $actor): bool
    {
        return $this->adminCan($actor, 'reviews.create');
    }

    public function update(User $actor, Review $review): bool
    {
        return $this->adminCan($actor, 'reviews.update');
    }

    /** Publish and hide: moderation. */
    public function moderate(User $actor, Review $review): bool
    {
        return $this->adminCan($actor, 'reviews.approve');
    }

    public function delete(User $actor, Review $review): bool
    {
        return $actor->isSuperAdmin();
    }
}
