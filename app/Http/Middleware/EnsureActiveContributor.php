<?php

namespace App\Http\Middleware;

use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware alias `active.contributor` (spec 4, 16). Only active contributors may reach
 * apply and assignment areas. Everyone else, including suspended and rejected, sees a clear status page.
 * Guests and other roles are refused as 403, so the alias is safe to use without `role:contributor`.
 */
class EnsureActiveContributor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isContributor(), 403);

        $user->loadMissing('profile');
        $state = $user->profile->onboarding_state ?? OnboardingState::Registered;

        if ($state === OnboardingState::Active && $user->status === UserStatus::Active) {
            return $next($request);
        }

        return response()->view('contributor.status', [
            'state' => $state,
            'suspended' => $user->status === UserStatus::Suspended,
        ], 403);
    }
}
