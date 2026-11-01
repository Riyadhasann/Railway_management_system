<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for fare rules and concessions: fares.view may look
 * (finance, manager), only fares.manage may change (admin).
 */
abstract class FarePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewFareRules->value)
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
        return $user->checkPermissionTo(Permission::ManageFareRules->value);
    }
}
