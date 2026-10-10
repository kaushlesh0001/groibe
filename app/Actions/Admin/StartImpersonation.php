<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Super admin only (spec section 4). Every start is written to the activity log. */
class StartImpersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function __invoke(User $admin, User $target): void
    {
        Gate::forUser($admin)->authorize('impersonate', $target);

        if (session()->has(self::SESSION_KEY)) {
            throw new LogicException('Already impersonating. Stop the current impersonation first.');
        }

        activity('impersonation')
            ->causedBy($admin)
            ->performedOn($target)
            ->event('started')
            ->log("Started impersonating {$target->email}");

        session()->put(self::SESSION_KEY, $admin->getKey());
        Auth::guard('web')->login($target);
        session()->regenerate();
    }
}
