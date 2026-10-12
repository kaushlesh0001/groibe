<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the routes inside a group as public pages that need no inline scripts, so SecurityHeaders gives them the strict
 * Content-Security-Policy, which it sets on the way out. It also stops Livewire injecting its scripts into these pages.
 */
class StrictContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        // The public pages have no Livewire components. If one ever renders, its injected scripts would be blocked by the
        // policy anyway, so Livewire is told not to add them.
        config(['livewire.inject_assets' => false]);

        return $next($request);
    }
}
