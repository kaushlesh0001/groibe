<?php

namespace Database\Factories;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Testimonial>
 */
class TestimonialFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'author_name' => fake()->name(),
            'author_title' => fake()->jobTitle(),
            'organization_name' => fake()->company(),
            'quote' => fake()->sentence(14),
            'photo_path' => null,
            'is_active' => true,
            'needs_review' => false,
            'sort' => fake()->numberBetween(1, 50),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function needsReview(): static
    {
        return $this->state(fn () => ['is_active' => false, 'needs_review' => true]);
    }
}
