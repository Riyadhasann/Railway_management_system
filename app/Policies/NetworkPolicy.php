<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for network records (stations, routes, trains, runs):
 * anyone with network.view may look, only network.manage may change.
 * Admins pass every check through Gate::before.
 */
abstract class NetworkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewNetwork->value)
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
        return $user->checkPermissionTo(Permission::ManageNetwork->value);
    }
}
