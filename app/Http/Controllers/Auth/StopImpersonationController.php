<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\StopImpersonation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class StopImpersonationController extends Controller
{
    public function __invoke(StopImpersonation $stop): RedirectResponse
    {
        abort_unless(session()->has(StartImpersonation::SESSION_KEY), 403);

        $stop();

        return redirect('/admin');
    }
}
