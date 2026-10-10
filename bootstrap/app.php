<?php

use App\Http\Middleware\EnsureActiveContributor;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(function (): void {
                require base_path('routes/public.php');
                require base_path('routes/auth.php');
                require base_path('routes/contributor.php');
                require base_path('routes/client.php');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'feature' => EnsureFeatureEnabled::class,
            'active.contributor' => EnsureActiveContributor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
