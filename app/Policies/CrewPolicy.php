<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for crew and locomotive records: assignments.view may look (manager),
 * assignments.manage may change (crew controller). Admins pass through Gate::before.
 */
abstract class CrewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewAssignments->value)
            || $this->manages($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    protected function manages(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ManageAssignments->value);
    }
}
