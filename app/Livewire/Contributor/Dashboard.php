<?php

namespace App\Livewire\Contributor;

use App\Enums\OnboardingState;
use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Contributor dashboard shell (spec 11): onboarding status for now, work and reputation panels arrive in later passes. */
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $profile = Profile::query()->where('user_id', $user->id)->first();

        return view('livewire.contributor.dashboard', [
            'state' => $profile->onboarding_state ?? OnboardingState::Registered,
            'profile' => $profile,
            'emailVerified' => $user->email_verified_at !== null,
        ]);
    }
}
