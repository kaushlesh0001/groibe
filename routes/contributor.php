<?php

use App\Http\Controllers\Contributor\ProfilePhotoController;
use App\Livewire\Contributor\Dashboard;
use App\Livewire\Contributor\EditProfile;
use App\Livewire\Contributor\Onboarding;
use App\Livewire\Contributor\Work\MyWork;
use App\Livewire\Contributor\Work\ProjectBoard;
use App\Livewire\Contributor\Work\TaskDetail;
use Illuminate\Support\Facades\Route;

// Onboarding, profile and the dashboard are open to every contributor, whatever their review state.
// Apply and assignment areas must add the `active.contributor` middleware (spec 4, 16): see the work group below.
Route::middleware(['auth', 'verified', 'role:contributor'])
    ->prefix('app/contributor')
    ->name('contributor.')
    ->group(function (): void {
        Route::livewire('/', Dashboard::class)->name('dashboard');
        Route::livewire('onboarding', Onboarding::class)->name('onboarding');
        Route::livewire('profile', EditProfile::class)->name('profile');
        Route::get('profile/photo', ProfilePhotoController::class)->name('profile.photo');
    });

// My Work (spec 11): assignments, task board and task detail. Active contributors only.
Route::middleware(['auth', 'verified', 'role:contributor', 'active.contributor'])
    ->prefix('app/contributor/work')
    ->name('contributor.work')
    ->group(function (): void {
        Route::livewire('/', MyWork::class)->name('');
        Route::livewire('projects/{project}', ProjectBoard::class)->name('.project');
        Route::livewire('tasks/{task}', TaskDetail::class)->name('.task');
    });
