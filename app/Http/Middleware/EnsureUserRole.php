<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware alias `role`, for example `role:contributor` (spec section 16). Checks users.role. */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowed = array_map(fn (string $role): UserRole => UserRole::from($role), $roles);

        abort_unless($user !== null && in_array($user->role, $allowed, true), 403);

        return $next($request);
    }
}
