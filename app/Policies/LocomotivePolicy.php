<?php

namespace App\Policies;

use App\Models\Locomotive;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LocomotivePolicy extends CrewPolicy
{
    /**
     * A loco with a workshop history is kept for the record (retire it instead).
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof Locomotive
            && ! $model->maintenances()->exists();
    }
}
