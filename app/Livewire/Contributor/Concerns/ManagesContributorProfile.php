<?php

namespace App\Livewire\Contributor\Concerns;

use App\Actions\Contributors\SaveContributorProfile;
use App\Actions\Contributors\SyncContributorSkills;
use App\Actions\Contributors\UploadProfilePhoto;
use App\Enums\SkillLevel;
use App\Livewire\Forms\ContributorProfileForm;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/** Profile, availability, skills and photo handling shared by the onboarding flow and the profile page. */
trait ManagesContributorProfile
{
    use WithFileUploads;

    #[Locked]
    public ?int $profileId = null;

    public ContributorProfileForm $form;

    public mixed $photo = null;

    protected function profile(): Profile
    {
        return Profile::query()->where('user_id', Auth::id())->firstOrFail();
    }

    protected function loadForm(): void
    {
        $user = Auth::user();
        $profile = $this->profile();

        $this->profileId = $profile->getKey();
        $this->form->fillFrom($profile, $user);
    }

    protected function saveProfileSection(): void
    {
        $this->form->validate($this->form->rulesForSection('profile'));
        app(SaveContributorProfile::class)($this->profile(), $this->form->profileData(), Auth::user());
    }

    protected function saveAvailabilityAndSkills(): void
    {
        $this->form->validate([
            ...$this->form->rulesForSection('availability'),
            ...$this->form->rulesForSection('skills'),
        ]);

        $skills = $this->form->selectedSkills();

        if ($skills === []) {
            $this->addError('form.skillLevels', 'Pick at least one skill and say how strong you are at it.');

            return;
        }

        $profile = $this->profile();

        app(SaveContributorProfile::class)($profile, $this->form->availabilityData(), Auth::user());
        app(SyncContributorSkills::class)($profile, $skills, Auth::user());
    }

    public function uploadPhoto(): void
    {
        app(UploadProfilePhoto::class)($this->profile(), $this->photo, Auth::user());

        $this->reset('photo');
        session()->flash('photo-status', 'Photo updated.');
    }

    public function removePhoto(): void
    {
        app(UploadProfilePhoto::class)->remove($this->profile(), Auth::user());
    }

    /** @return Collection<int|string, Collection<int, Skill>> active skills grouped by category name */
    protected function skillGroups(): Collection
    {
        return Skill::query()->active()->with('category:id,name,sort')->orderBy('name')->get()
            ->toBase()
            ->groupBy(fn (Skill $skill) => $skill->category->name ?? 'Other')
            ->sortKeys();
    }

    /** @return list<SkillLevel> */
    protected function levels(): array
    {
        return SkillLevel::cases();
    }
}
