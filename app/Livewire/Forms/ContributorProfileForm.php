<?php

namespace App\Livewire\Forms;

use App\Enums\Availability;
use App\Enums\SkillLevel;
use App\Models\Profile;
use App\Models\SkillUser;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Contributor profile, availability and skill fields with the rules for each onboarding section (spec 7.1, 7.2, 11). */
class ContributorProfileForm extends Form
{
    public string $headline = '';

    public string $bio = '';

    public string $phone = '';

    public string $city = '';

    public string $state = '';

    public string $country = 'India';

    public string $linkedin_url = '';

    public string $portfolio_url = '';

    public ?int $experience_years = null;

    /** Comma separated, stored as a json list. */
    public string $languages = '';

    public string $availability = '';

    public ?int $hours_per_week = null;

    /** @var array<int, string> */
    public array $work_modes = [];

    /** @var array<int|string, string> skill id => level, empty string means not selected */
    public array $skillLevels = [];

    public const WORK_MODES = ['remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'On site'];

    public function fillFrom(Profile $profile, User $user): void
    {
        $this->headline = (string) $profile->headline;
        $this->bio = (string) $profile->bio;
        $this->phone = (string) $profile->phone;
        $this->city = (string) $profile->city;
        $this->state = (string) $profile->state;
        $this->country = (string) ($profile->country ?? 'India');
        $this->linkedin_url = (string) $profile->linkedin_url;
        $this->portfolio_url = (string) $profile->portfolio_url;
        $this->experience_years = $profile->experience_years;
        $this->languages = implode(', ', $profile->languages ?? []);
        $this->availability = $profile->availability->value ?? '';
        $this->hours_per_week = $profile->hours_per_week;
        $this->work_modes = $profile->work_modes ?? [];

        $this->skillLevels = SkillUser::query()->where('user_id', $user->getKey())->get()
            ->mapWithKeys(fn (SkillUser $row) => [$row->skill_id => $row->level->value])
            ->all();
    }

    /** @return array<string, mixed> */
    public function rulesForSection(string $section): array
    {
        return match ($section) {
            'profile' => [
                'headline' => ['required', 'string', 'max:160'],
                'bio' => ['required', 'string', 'min:30', 'max:3000'],
                'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]*$/'],
                'city' => ['required', 'string', 'max:120'],
                'state' => ['nullable', 'string', 'max:120'],
                'country' => ['required', 'string', 'max:120'],
                'linkedin_url' => ['nullable', 'url:https', 'max:255'],
                'portfolio_url' => ['nullable', 'url:https', 'max:255'],
                'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
                'languages' => ['nullable', 'string', 'max:255'],
            ],
            'availability' => [
                'availability' => ['required', Rule::enum(Availability::class)],
                'hours_per_week' => ['nullable', 'integer', 'min:1', 'max:80'],
                'work_modes' => ['array'],
                'work_modes.*' => [Rule::in(array_keys(self::WORK_MODES))],
            ],
            'skills' => [
                'skillLevels' => ['array', 'max:60'],
                'skillLevels.*' => ['nullable', Rule::enum(SkillLevel::class)],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function profileData(): array
    {
        return [
            'headline' => trim($this->headline),
            'bio' => trim($this->bio),
            'phone' => $this->phone !== '' ? $this->phone : null,
            'city' => trim($this->city),
            'state' => $this->state !== '' ? trim($this->state) : null,
            'country' => trim($this->country),
            'linkedin_url' => $this->linkedin_url !== '' ? $this->linkedin_url : null,
            'portfolio_url' => $this->portfolio_url !== '' ? $this->portfolio_url : null,
            'experience_years' => $this->experience_years,
            'languages' => array_values(array_filter(array_map('trim', explode(',', $this->languages)))),
        ];
    }

    /** @return array<string, mixed> */
    public function availabilityData(): array
    {
        return [
            'availability' => $this->availability,
            'hours_per_week' => $this->hours_per_week,
            'work_modes' => array_values($this->work_modes),
        ];
    }

    /** @return array<int, string> skill id => level, only the selected skills */
    public function selectedSkills(): array
    {
        return collect($this->skillLevels)
            ->filter(fn ($level) => filled($level))
            ->mapWithKeys(fn ($level, $id) => [(int) $id => (string) $level])
            ->all();
    }
}
