<?php

use App\Http\Controllers\Contributor\ProfilePhotoController;
use App\Livewire\Contributor\Dashboard;
use App\Livewire\Contributor\EditProfile;
use App\Livewire\Contributor\Onboarding;
use Illuminate\Support\Facades\Route;

// Onboarding, profile and the dashboard are open to every contributor, whatever their review state.
// Apply and assignment areas added in later passes must add the `active.contributor` middleware (spec 4, 16).
Route::middleware(['auth', 'verified', 'role:contributor'])
    ->prefix('app/contributor')
    ->name('contributor.')
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('dashboard');
        Route::livewire('onboarding', Onboarding::class)->name('onboarding');
        Route::livewire('profile', EditProfile::class)->name('profile');
        Route::get('profile/photo', ProfilePhotoController::class)->name('profile.photo');
    });
