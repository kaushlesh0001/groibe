<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:contributor'])
    ->prefix('app/contributor')
    ->name('contributor.')
    ->group(function (): void {
        Route::view('/', 'contributor.dashboard')->name('dashboard');
    });
