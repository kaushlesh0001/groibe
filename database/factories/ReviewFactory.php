<?php

namespace Database\Factories;

use App\Enums\ReviewDirection;
use App\Enums\ReviewRevieweeType;
use App\Enums\ReviewStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'reviewer_id' => User::factory()->client(),
            'reviewee_type' => ReviewRevieweeType::User,
            'reviewee_id' => User::factory()->contributor(),
            'direction' => ReviewDirection::ClientToContributor,
            'quality' => 5,
            'communication' => 4,
            'reliability' => 5,
            'timeliness' => 4,
            'comment' => fake()->sentence(),
            'status' => ReviewStatus::PendingModeration,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => ReviewStatus::Published, 'published_at' => now()]);
    }

    /** A contributor's review of the client organization. */
    public function ofClient(?Organization $organization = null): static
    {
        return $this->state(fn () => [
            'reviewer_id' => User::factory()->contributor(),
            'reviewee_type' => ReviewRevieweeType::Organization,
            'reviewee_id' => $organization ?? Organization::factory(),
            'direction' => ReviewDirection::ContributorToClient,
            'quality' => null,
            'reliability' => null,
            'timeliness' => null,
            'clarity' => 4,
            'communication' => 4,
            'professionalism' => 5,
            'feedback_time' => 3,
            'fairness' => 5,
        ]);
    }

    /** An admin's internal review of a contributor. */
    public function internal(): static
    {
        return $this->state(fn () => [
            'reviewer_id' => User::factory()->admin(),
            'direction' => ReviewDirection::AdminToContributor,
        ]);
    }
}
