<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceRef;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceRef>
 */
class InvoiceRefFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'zoho_invoice_ref' => 'INV-'.fake()->unique()->numerify('######'),
            'amount' => 50000,
            'currency' => 'INR',
            'status' => InvoiceStatus::Draft,
        ];
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'issued_at' => $status === InvoiceStatus::Draft ? null : now()->subDays(10)->toDateString(),
            'due_at' => $status === InvoiceStatus::Draft ? null : now()->addDays(20)->toDateString(),
        ]);
    }
}
