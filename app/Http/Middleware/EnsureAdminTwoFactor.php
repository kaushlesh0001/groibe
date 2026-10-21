<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication for the admin panel (spec 17.2, SCR-029, SCR-040). A super admin must always have it. Any other admin
 * must have it while the `two_factor_admin` flag is on. Since SCR-040 the emailed sign-in code is decided by role and flag
 * (`User::mustUseEmailCode()`), so there is nothing to set up. This stays as a guard on every admin page that the panel really
 * offers such an admin a second factor: if it did not (for example the email provider were taken off the panel), the admin is sent
 * to Filament's set-up page, the only admin page they can open until they finish.
 *
 * Filament runs the sign-in challenge itself for anyone who has a factor, whatever this middleware or the flag says.
 */
class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->required($user) || MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function required(User $user): bool
    {
        return $user->mustUseEmailCode();
    }
}
