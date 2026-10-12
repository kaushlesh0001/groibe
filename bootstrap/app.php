<?php

use App\Http\Middleware\EnsureAccountNotSuspended;
use App\Http\Middleware\EnsureActiveContributor;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StrictContentSecurityPolicy;
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
                // Public pages need no inline scripts, so they get the strict Content-Security-Policy (spec 17.2).
                Route::middleware(SecurityHeaders::STRICT)->group(base_path('routes/public.php'));
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
            SecurityHeaders::STRICT => StrictContentSecurityPolicy::class,
        ]);

        // Spec 17.2: security headers on every response, including redirects and errors. It is global, not part of the web
        // group, because Laravel runs `auth` ahead of group middleware and a redirect it throws would otherwise skip the headers.
        $middleware->append(SecurityHeaders::class);

        // Spec 4, 14: a suspended person loses access at once, even with a session that is already open.
        $middleware->appendToGroup('web', EnsureAccountNotSuspended::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
