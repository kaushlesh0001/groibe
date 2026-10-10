<?php

namespace Database\Factories;

use App\Enums\AgreementType;
use App\Models\AgreementTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgreementTemplate>
 */
class AgreementTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => AgreementType::ClientMsa,
            'version' => 1,
            'title' => 'Placeholder agreement',
            'body_html' => '<p>Placeholder text. Replace with approved legal text.</p>',
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ];
    }

    public function ofType(AgreementType $type): static
    {
        return $this->state(fn () => ['type' => $type, 'title' => $type->getLabel()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function sowTemplate(): static
    {
        return $this->ofType(AgreementType::SowTemplate)->state(fn () => [
            'body_html' => '<h1>{{project_title}}</h1><p>Client: {{client_name}}</p><div>{{scope}}</div>{{milestones_table}}'
                .'<p>Total: {{total_value}}</p><p>Payment terms: {{payment_terms}}</p><p>{{start_date}} to {{due_date}}</p>',
        ]);
    }
}
