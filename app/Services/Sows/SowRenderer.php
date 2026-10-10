<?php

namespace App\Services\Sows;

use App\Models\AgreementTemplate;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Substitutes spec 8 variables into a SOW template. Values are HTML-escaped; the template body itself is
 * trusted admin content. Unknown placeholders are left untouched so mistakes stay visible in review.
 */
class SowRenderer
{
    /**
     * @param  array<string, string|null>  $overrides  total_value, payment_terms, scope, start_date, due_date
     * @return array{html: string, variables: array<string, string>}
     */
    public function render(AgreementTemplate $template, Project $project, array $overrides = []): array
    {
        $project->loadMissing('organization');

        $variables = [
            'client_name' => $project->organization->legal_name ?: $project->organization->name,
            'project_title' => $project->title,
            'scope' => $overrides['scope'] ?? $project->scope_summary ?? '',
            'total_value' => $this->money($overrides['total_value'] ?? $project->contract_value, $project->currency),
            'payment_terms' => $overrides['payment_terms'] ?? '',
            'start_date' => $this->date($overrides['start_date'] ?? $project->start_date),
            'due_date' => $this->date($overrides['due_date'] ?? $project->due_date),
        ];

        $search = [];
        $replace = [];

        foreach ($variables as $key => $value) {
            $search[] = '{{'.$key.'}}';
            $replace[] = $key === 'scope' ? nl2br(e($value)) : e($value);
        }

        // Milestones do not exist until Pass 4 (SCR-007).
        $search[] = '{{milestones_table}}';
        $replace[] = '<p>No milestones have been defined yet.</p>';

        return [
            'html' => str_replace($search, $replace, $template->body_html),
            'variables' => $variables,
        ];
    }

    private function money(string|float|int|null $amount, string $currency): string
    {
        return $amount === null || $amount === '' ? 'To be confirmed' : $currency.' '.number_format((float) $amount, 2);
    }

    private function date(mixed $date): string
    {
        if ($date === null || $date === '') {
            return 'To be confirmed';
        }

        return Carbon::parse($date)->format('j M Y');
    }
}
