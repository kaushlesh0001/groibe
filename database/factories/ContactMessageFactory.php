<?php

namespace Database\Factories;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
            'message' => fake()->paragraph(),
            'ip' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (test)',
            'status' => ContactMessageStatus::New,
        ];
    }

    public function status(ContactMessageStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
