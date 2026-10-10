<?php

namespace Database\Seeders;

use App\Enums\AgreementType;
use App\Models\AgreementTemplate;
use Illuminate\Database\Seeder;

/**
 * Placeholder bodies only (spec 8, 19). The founder pastes the real legal text through the admin panel,
 * which publishes it as a new version. Legal wording is never hard-coded here.
 */
class AgreementTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AgreementType::cases() as $type) {
            AgreementTemplate::query()->firstOrCreate(
                ['type' => $type, 'version' => 1],
                [
                    'title' => $type->getLabel(),
                    'body_html' => $type === AgreementType::SowTemplate
                        ? '<h1>Statement of Work: {{project_title}}</h1>'
                            .'<p>Prepared for {{client_name}}.</p>'
                            .'<h2>Scope</h2><div>{{scope}}</div>'
                            .'<h2>Milestones</h2>{{milestones_table}}'
                            .'<h2>Commercials</h2><p>Total value: {{total_value}}</p><p>Payment terms: {{payment_terms}}</p>'
                            .'<h2>Timeline</h2><p>Start: {{start_date}}. Due: {{due_date}}.</p>'
                            .'<p>Placeholder template. Replace with the approved SOW wording.</p>'
                        : '<p>Placeholder text for '.$type->getLabel().'. Replace with the approved legal text.</p>',
                    'is_active' => true,
                    'effective_from' => now(),
                ],
            );
        }
    }
}
