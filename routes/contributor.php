<?php

use App\Http\Controllers\Contributor\ProfilePhotoController;
use App\Livewire\Contributor\Dashboard;
use App\Livewire\Contributor\Discover;
use App\Livewire\Contributor\EditProfile;
use App\Livewire\Contributor\MyApplications;
use App\Livewire\Contributor\Onboarding;
use App\Livewire\Contributor\Portfolio;
use App\Livewire\Contributor\Reputation;
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

// Discover and My Applications (spec 11). Active contributors only, and gone entirely when the `open_roles` flag is off.
Route::middleware(['auth', 'verified', 'role:contributor', 'active.contributor', 'feature:open_roles'])
    ->prefix('app/contributor')
    ->name('contributor.')
    ->group(function (): void {
        Route::livewire('discover', Discover\Index::class)->name('discover');
        Route::livewire('discover/{slug}', Discover\Show::class)->name('discover.show');
        Route::livewire('applications', MyApplications::class)->name('applications');
    });

// Portfolio and Reputation (spec 11). Active contributors only: drafts exist once a project they worked on closes.
Route::middleware(['auth', 'verified', 'role:contributor', 'active.contributor'])
    ->prefix('app/contributor')
    ->name('contributor.')
    ->group(function (): void {
        Route::livewire('portfolio', Portfolio\Index::class)->name('portfolio');
        Route::livewire('portfolio/{proof}', Portfolio\Edit::class)->name('portfolio.edit');
        Route::livewire('reputation', Reputation::class)->name('reputation');
    });
