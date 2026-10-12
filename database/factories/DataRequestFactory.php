<?php

namespace Database\Factories;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataRequest>
 */
class DataRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->contributor(),
            'type' => DataRequestType::Export,
            'status' => DataRequestStatus::Open,
        ];
    }

    public function status(DataRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function deletion(): static
    {
        return $this->state(fn () => ['type' => DataRequestType::Deletion]);
    }
}
