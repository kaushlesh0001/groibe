<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/** Spec section 5: audit log is read-only. Super admin reads all; other admins read their own actions. */
class ActivityPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->status === UserStatus::Suspended ? false : null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin() && ($actor->can('audit_log.view') || $actor->can('audit_log.view_all'));
    }

    public function view(User $actor, Activity $activity): bool
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        if ($actor->can('audit_log.view_all')) {
            return true;
        }

        return $actor->can('audit_log.view')
            && $activity->causer_type === $actor->getMorphClass()
            && (int) $activity->causer_id === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $actor, Activity $activity): bool
    {
        return false;
    }
}
