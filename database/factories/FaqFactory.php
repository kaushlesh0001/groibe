<?php

namespace Database\Factories;

use App\Enums\FaqAudience;
use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'audience' => FaqAudience::General,
            'question' => rtrim(fake()->sentence(7), '.').'?',
            'answer' => fake()->paragraph(),
            'sort' => fake()->numberBetween(1, 50),
            'is_active' => true,
            'needs_review' => false,
        ];
    }

    public function audience(FaqAudience $audience): static
    {
        return $this->state(fn () => ['audience' => $audience]);
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
