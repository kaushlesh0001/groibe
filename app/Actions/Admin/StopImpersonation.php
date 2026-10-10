<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LogicException;

class StopImpersonation
{
    public function __invoke(): User
    {
        $adminId = session()->get(StartImpersonation::SESSION_KEY);
        $target = Auth::user();

        if ($adminId === null || $target === null) {
            throw new LogicException('No impersonation in progress.');
        }

        $admin = User::query()->findOrFail($adminId);

        activity('impersonation')
            ->causedBy($admin)
            ->performedOn($target)
            ->event('stopped')
            ->log("Stopped impersonating {$target->email}");

        session()->forget(StartImpersonation::SESSION_KEY);
        Auth::guard('web')->login($admin);
        session()->regenerate();

        return $admin;
    }
}
