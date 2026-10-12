<?php

namespace App\Services\Imports;

use App\Enums\Availability;
use App\Enums\ImportType;
use App\Enums\OnboardingState;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The contributors CSV (spec 15.1). Idempotent by email: an email that already has an account is skipped, never
 * overwritten, so a second run of the same file changes nothing. People arrive `pending` with no password and are
 * invited afterwards. Unknown skills are created inactive and attached, and reported on the row that introduced them.
 */
class ContributorImport extends ImportDefinition
{
    /** Skills named on one row. */
    private const MAX_SKILLS = 20;

    /** @var array<string, true> emails seen earlier in this run */
    private array $seenEmails = [];

    /** @var array<string, Skill|true|null> lower-cased skill name => the skill, true when a dry run plans to create it, null when it does not exist */
    private array $skills = [];

    public function type(): ImportType
    {
        return ImportType::Contributors;
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Name', 'required' => true],
            'email' => ['label' => 'Email', 'required' => true],
            'phone' => ['label' => 'Phone', 'required' => false],
            'city' => ['label' => 'City', 'required' => false],
            'state' => ['label' => 'State', 'required' => false],
            'headline' => ['label' => 'Headline', 'required' => false],
            'bio' => ['label' => 'Bio', 'required' => false],
            'skills' => ['label' => 'Skills (separated by semicolons)', 'required' => false],
            'experience_years' => ['label' => 'Years of experience', 'required' => false],
            'linkedin_url' => ['label' => 'LinkedIn URL', 'required' => false],
            'availability' => ['label' => 'Availability', 'required' => false],
            'status' => ['label' => 'Status (pending)', 'required' => false],
        ];
    }

    public function process(array $row, int $line): RowResult
    {
        $row['email'] = Str::lower($row['email'] ?? '');
        $row['availability'] = Str::lower($row['availability'] ?? '');
        $row['status'] = Str::lower($row['status'] ?? '');

        $validator = Validator::make($row, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'skills' => ['nullable', 'string', 'max:2000'],
            'experience_years' => ['nullable', 'integer', 'between:0,60'],
            'linkedin_url' => ['nullable', 'url:http,https', 'max:255'],
            'availability' => ['nullable', 'in:'.implode(',', array_column(Availability::cases(), 'value'))],
            'status' => ['nullable', 'in:pending'],
        ], [
            'status.in' => 'Imported people always start as pending. Leave the status empty or write pending.',
        ]);

        $errors = $validator->errors()->all();
        $skillNames = $this->skillNames($row['skills'] ?? '', $errors);

        if ($errors !== []) {
            return RowResult::failed($errors);
        }

        $existing = User::withTrashed()->where('email', $row['email'])->first();

        if ($existing !== null || isset($this->seenEmails[$row['email']])) {
            if ($existing !== null && $existing->role !== UserRole::Contributor) {
                return RowResult::failed(['This email belongs to a '.$existing->role->value.' account, not a contributor.']);
            }

            return RowResult::skipped('An account with this email already exists.');
        }

        $this->seenEmails[$row['email']] = true;

        return RowResult::created($this->commit ? $this->create($row, $skillNames, $line) : $this->plan($skillNames));
    }

    /** @param  list<string>  $errors  extended with any problem found @return list<string> */
    private function skillNames(string $raw, array &$errors): array
    {
        $names = [];

        foreach (explode(';', $raw) as $name) {
            $name = trim($name);

            if ($name !== '') {
                $names[Str::lower($name)] ??= $name;
            }
        }

        if (count($names) > self::MAX_SKILLS) {
            $errors[] = 'No more than '.self::MAX_SKILLS.' skills on one row.';
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > 100) {
                $errors[] = 'A skill name is longer than 100 characters.';
            }
        }

        return array_values($names);
    }

    /** @param  list<string>  $skillNames @return list<string> notes */
    private function plan(array $skillNames): array
    {
        $notes = [];

        foreach ($skillNames as $name) {
            if ($this->resolveSkill($name) === null) {
                $this->skills[Str::lower($name)] = true;
                $notes[] = "Unknown skill \"{$name}\" will be created inactive.";
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $skillNames
     * @return list<string> notes
     */
    private function create(array $row, array $skillNames, int $line): array
    {
        $notes = [];

        DB::transaction(function () use ($row, $skillNames, $line, &$notes): void {
            $user = User::query()->create([
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => UserRole::Contributor,
                'status' => UserStatus::Pending,
            ]);
            $user->assignRole(UserRole::Contributor->value);

            Profile::query()->create([
                'user_id' => $user->getKey(),
                'headline' => $row['headline'] ?: null,
                'bio' => $row['bio'] ?: null,
                'phone' => $row['phone'] ?: null,
                'city' => $row['city'] ?: null,
                'state' => $row['state'] ?: null,
                'linkedin_url' => $row['linkedin_url'] ?: null,
                'availability' => $row['availability'] ?: null,
                'experience_years' => $row['experience_years'] === '' ? null : (int) $row['experience_years'],
                'onboarding_state' => OnboardingState::Registered,
            ]);

            $attach = [];

            foreach ($skillNames as $name) {
                $skill = $this->resolveSkill($name);

                if ($skill === null) {
                    $skill = $this->createInactiveSkill($name);
                    $notes[] = "Unknown skill \"{$name}\" was created inactive.";
                }

                // Imported skills start unverified at the table's default level; an admin verifies what they have seen.
                $attach[$skill->getKey()] = ['level' => SkillLevel::Intermediate->value, 'is_verified' => false];
            }

            $user->skills()->sync($attach);

            $this->logCreated($user, $line);
        });

        return $notes;
    }

    private function resolveSkill(string $name): ?Skill
    {
        $key = Str::lower($name);

        if (! array_key_exists($key, $this->skills)) {
            $this->skills[$key] = Skill::query()->whereRaw('lower(name) = ?', [$key])->first();
        }

        $known = $this->skills[$key];

        return $known instanceof Skill ? $known : null;
    }

    private function createInactiveSkill(string $name): Skill
    {
        $base = Str::slug($name) ?: 'skill';
        $slug = $base;

        for ($i = 2; Skill::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $skill = Skill::query()->create(['name' => $name, 'slug' => $slug, 'category_id' => null, 'is_active' => false]);
        $this->skills[Str::lower($name)] = $skill;

        return $skill;
    }
}
