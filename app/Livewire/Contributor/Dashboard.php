<?php

namespace App\Livewire\Contributor;

use App\Enums\ApplicationStatus;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Models\Application;
use App\Models\Profile;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\OpenRoles\RoleRecommender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Contributor dashboard shell (spec 11): onboarding status for now, work and reputation panels arrive in later passes. */
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(FeatureFlags $flags, RoleRecommender $recommender)
    {
        $user = Auth::user();
        $profile = Profile::query()->where('user_id', $user->id)->first();
        $rolesOn = $user->isActiveContributor() && $flags->enabled(FeatureFlag::OpenRoles);

        return view('livewire.contributor.dashboard', [
            'state' => $profile->onboarding_state ?? OnboardingState::Registered,
            'profile' => $profile,
            'emailVerified' => $user->email_verified_at !== null,
            'rolesOn' => $rolesOn,
            'recommended' => $rolesOn ? $recommender->for($user) : new Collection,
            'offers' => $rolesOn ? Application::query()->forUser($user)->where('status', ApplicationStatus::Offered->value)->count() : 0,
        ]);
    }
}
