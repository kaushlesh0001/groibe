<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:client'])
    ->prefix('app/client')
    ->name('client.')
    ->group(function (): void {
        Route::view('/', 'client.dashboard')->name('dashboard');
    });
