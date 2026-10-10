<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlags\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware alias `feature`, for example `feature:contributor_signup`. A disabled flag returns 404. */
class EnsureFeatureEnabled
{
    public function __construct(private FeatureFlags $flags) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        abort_if($this->flags->disabled($flag), 404);

        return $next($request);
    }
}
