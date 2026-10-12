<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => ImportType::Contributors,
            'original_name' => 'contributors.csv',
            'file_path' => 'imports/'.fake()->unique()->lexify('????????').'.csv',
            'status' => ImportStatus::Uploaded,
            'dry_run' => true,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function status(ImportStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function clients(): static
    {
        return $this->state(fn () => ['type' => ImportType::Clients, 'original_name' => 'clients.csv']);
    }
}
