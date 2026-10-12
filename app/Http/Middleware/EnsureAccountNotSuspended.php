<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended client or admin loses access at once (spec 4, 14). Sign-in already refuses a suspended user, but a session, a
 * "remember me" cookie or a page that is already open would carry on working. On every web request, a signed-in client or admin
 * who is now suspended is signed out and sent back to sign in. A Livewire request gets a plain refusal instead, because it expects data, not a page.
 *
 * Impersonation follows the same rule. The policy only lets a super admin impersonate an active account, and if that account is
 * suspended while the admin is acting as it, the session ends like any other.
 */
class EnsureAccountNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Contributors are left to their own rules, which are deliberate: a suspended contributor keeps a status page, the
        // dashboard, their profile and the support thread (spec 14: support is open to every contributor, whatever their state),
        // and EnsureActiveContributor closes every page that handles work, money or applications.
        if ($user === null || $user->status !== UserStatus::Suspended || $user->isContributor()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->hasHeader('X-Livewire'), 403, 'This account is suspended.');

        return redirect()->route('login')->with('status', __('This account is suspended. Contact Groibe if you think this is a mistake.'));
    }
}
