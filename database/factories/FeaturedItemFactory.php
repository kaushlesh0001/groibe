<?php

namespace Database\Factories;

use App\Enums\FeaturedItemType;
use App\Models\FeaturedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeaturedItem>
 */
class FeaturedItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => FeaturedItemType::OpenRole,
            'item_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'sort' => fake()->numberBetween(1, 50),
            'is_active' => true,
        ];
    }

    public function of(FeaturedItemType $type, int $itemId): static
    {
        return $this->state(fn () => ['type' => $type, 'item_id' => $itemId]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
