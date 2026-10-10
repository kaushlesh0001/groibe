<?php

namespace App\Livewire\Forms;

use App\Enums\Confidentiality;
use App\Models\Brief;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Brief fields and the validation rules for each step of the guided form (spec 12). */
class BriefDetailsForm extends Form
{
    public string $title = '';

    public string $problem = '';

    public string $desired_outcome = '';

    public ?int $project_type_id = null;

    /** @var list<int|string> */
    public array $skill_ids = [];

    public string $budget_band = '';

    public string $timeline_band = '';

    public ?string $target_start = null;

    public ?string $deadline = null;

    public string $confidentiality = 'standard';

    public const STEPS = 5;

    /** @return array<string, mixed> */
    public function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'title' => ['required', 'string', 'max:255'],
                'problem' => ['required', 'string', 'min:20', 'max:5000'],
                'desired_outcome' => ['required', 'string', 'min:10', 'max:5000'],
            ],
            2 => [
                'project_type_id' => ['required', 'integer', Rule::exists('project_types', 'id')->where('is_active', true)],
                'skill_ids' => ['array', 'max:20'],
                'skill_ids.*' => ['integer', Rule::exists('skills', 'id')->where('is_active', true)],
            ],
            3 => [
                'budget_band' => ['required', Rule::in(array_keys(config('groibe.briefs.budget_bands')))],
                'timeline_band' => ['required', Rule::in(array_keys(config('groibe.briefs.timeline_bands')))],
                'target_start' => ['nullable', 'date', 'after_or_equal:today'],
                'deadline' => ['nullable', 'date', 'after_or_equal:target_start'],
            ],
            4 => [
                'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function allRules(): array
    {
        return array_merge(...array_map(fn (int $step): array => $this->rulesForStep($step), range(1, self::STEPS)));
    }

    public function fillFrom(Brief $brief): void
    {
        $this->title = (string) $brief->title;
        $this->problem = (string) $brief->problem;
        $this->desired_outcome = (string) $brief->desired_outcome;
        $this->project_type_id = $brief->project_type_id;
        $this->skill_ids = $brief->skills->modelKeys();
        $this->budget_band = (string) $brief->budget_band;
        $this->timeline_band = (string) $brief->timeline_band;
        $this->target_start = $brief->target_start?->toDateString();
        $this->deadline = $brief->deadline?->toDateString();
        $this->confidentiality = $brief->confidentiality->value;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'problem' => $this->problem,
            'desired_outcome' => $this->desired_outcome,
            'project_type_id' => $this->project_type_id,
            'budget_band' => $this->budget_band !== '' ? $this->budget_band : null,
            'timeline_band' => $this->timeline_band !== '' ? $this->timeline_band : null,
            'target_start' => filled($this->target_start) ? $this->target_start : null,
            'deadline' => filled($this->deadline) ? $this->deadline : null,
            'confidentiality' => $this->confidentiality,
        ];
    }

    /** @return list<int> */
    public function skillIds(): array
    {
        return array_map('intval', $this->skill_ids);
    }
}
