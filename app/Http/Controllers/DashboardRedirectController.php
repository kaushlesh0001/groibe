<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** `/dashboard` sends each role to its own app (spec section 16). */
class DashboardRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $role = $request->user()->role;

        return match ($role) {
            UserRole::Admin => redirect('/admin'),
            UserRole::Contributor => redirect()->route('contributor.dashboard'),
            UserRole::Client => redirect()->route('client.dashboard'),
        };
    }
}
