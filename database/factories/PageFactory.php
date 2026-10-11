<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'title' => Str::title($title),
            'body_html' => '<p>'.fake()->paragraph().'</p>',
            'meta_title' => null,
            'meta_description' => fake()->sentence(12),
            'is_active' => true,
            'needs_review' => false,
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
