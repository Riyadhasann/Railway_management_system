<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * create = generate runs, update = cancel a run. Runs are never deleted.
 */
class TrainRunPolicy extends NetworkPolicy
{
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
