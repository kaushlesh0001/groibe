<?php

namespace App\Http\Middleware;

use App\Enums\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication for the admin panel (spec 17.2, SCR-029). A super admin must always have it. Any other admin
 * must have it while the `two_factor_admin` flag is on. An admin who is required to have it and has not set it up is sent
 * to Filament's set-up page, which is the only admin page they can open until they finish.
 *
 * Filament runs the sign-in challenge itself for anyone who has set it up, whatever this middleware or the flag says.
 */
class EnsureAdminTwoFactor
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $this->required($user) || $user->hasTwoFactor()) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }

    public function required(User $user): bool
    {
        return $user->isSuperAdmin() || $this->flags->enabled(FeatureFlag::TwoFactorAdmin);
    }
}
