<?php

namespace Database\Factories;

use App\Models\CmsSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CmsSection>
 */
class CmsSectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'section_'.fake()->unique()->lexify('????????'),
            'title' => fake()->words(2, true),
            'content' => ['heading' => fake()->sentence(4)],
            'sort' => fake()->numberBetween(1, 50),
            'is_active' => true,
            'needs_review' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Seeded content the founder has not approved: inactive and flagged. */
    public function needsReview(): static
    {
        return $this->state(fn () => ['is_active' => false, 'needs_review' => true]);
    }
}
