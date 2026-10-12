<?php

use App\Http\Controllers\Auth\StopImpersonationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DashboardRedirectController;
use App\Http\Controllers\MediaDownloadController;
use App\Livewire\Actions\Logout;
use App\Livewire\Notifications\NotificationCentre;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\Privacy;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'auth.login')->name('login');

    Volt::route('register/contributor', 'auth.register-contributor')
        ->middleware('feature:contributor_signup')
        ->name('register.contributor');

    Volt::route('register/client', 'auth.register-client')
        ->middleware('feature:self_serve_client_signup')
        ->name('register.client');

    Route::redirect('register', 'register/contributor')->name('register');

    Volt::route('forgot-password', 'auth.forgot-password')->name('password.request');

    Volt::route('reset-password/{token}', 'auth.reset-password')->name('password.reset');
});

// Admin-created users activate through a signed, expiring link (spec 6.11, 16).
Volt::route('invite/{token}', 'auth.invite')
    ->middleware(['guest', 'signed', 'throttle:invite'])
    ->name('invite.show');

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'auth.verify-email')->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'auth.confirm-password')->name('password.confirm');

    Route::get('dashboard', DashboardRedirectController::class)
        ->middleware('verified')
        ->name('dashboard');

    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Route::livewire('settings/notifications', NotificationSettings::class)->name('settings.notifications');
    Route::livewire('settings/privacy', Privacy::class)->name('settings.privacy');

    // The notification centre (spec 14) and its bell, for every signed-in role.
    Route::livewire('notifications', NotificationCentre::class)->name('notifications.index');

    Route::post('impersonate/stop', StopImpersonationController::class)->name('impersonate.stop');

    // Private files: signed link, signed-in user, and the owning record's policy (spec 17.2).
    Route::get('files/{media:uuid}', MediaDownloadController::class)
        ->middleware(['verified', 'signed', 'throttle:60,1'])
        ->name('files.show');
});

Route::post('logout', Logout::class)->name('logout');
