<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log is read-only and for audit.view (admin) only.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewAuditLog->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
