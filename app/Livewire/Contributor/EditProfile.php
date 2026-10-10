<?php

namespace App\Livewire\Contributor;

use App\Actions\Contributors\SetProfileVisibility;
use App\Livewire\Contributor\Concerns\ManagesContributorProfile;
use App\Livewire\Forms\ContributorProfileForm;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Profile management (spec 11): details, skills with levels, photo and the public toggle. */
#[Title('Profile')]
class EditProfile extends Component
{
    use ManagesContributorProfile;

    public bool $isPublic = false;

    public function mount(): void
    {
        $this->loadForm();
        $this->isPublic = (bool) $this->profile()->is_public;
    }

    public function save(): void
    {
        $this->resetErrorBag();

        $this->saveProfileSection();
        $this->saveAvailabilityAndSkills();

        if ($this->getErrorBag()->isEmpty()) {
            session()->flash('status', 'Profile saved.');
        }
    }

    public function updatedIsPublic(bool $value): void
    {
        app(SetProfileVisibility::class)($this->profile(), $value, Auth::user());
    }

    public function render()
    {
        $profile = $this->profile();

        return view('livewire.contributor.edit-profile', [
            'profile' => $profile,
            'activeAndPublic' => $profile->isPubliclyVisible(),
            'skillGroups' => $this->skillGroups(),
            'levels' => $this->levels(),
            'workModes' => ContributorProfileForm::WORK_MODES,
            'photoUrl' => $profile->photo_path !== null ? route('contributor.profile.photo') : null,
        ]);
    }
}
