<?php

namespace App\Services\Admin;

use App\Actions\Admin\StartImpersonation;
use App\Exceptions\ActionBlocked;

/**
 * What the session knows about impersonation (spec 4). While a super admin looks at someone's account, two things hold
 * (review finding, Pass 9 item 8): everything written to the activity log names the admin as well as the person, and the acts
 * that bind a person (accepting a Statement of Work, the Client MSA or a milestone, showcase consent, a data request) are
 * refused, because a binding acceptance recorded as the client's own, with the admin's address, would be false evidence.
 * Reading the pages, and all admin work done as the admin, are unaffected.
 */
class Impersonation
{
    /** The admin behind the current request, or null when nobody is being impersonated (also null on the console and in jobs). */
    public function impersonatorId(): ?int
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return null;
        }

        $id = request()->session()->get(StartImpersonation::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    public function active(): bool
    {
        return $this->impersonatorId() !== null;
    }

    /** @throws ActionBlocked */
    public function assertNotImpersonating(): void
    {
        if ($this->active()) {
            throw ActionBlocked::guardFailed('You are viewing this account as an admin. Only the person themselves can do this. Stop impersonating first.');
        }
    }
}
