<?php

namespace App\Policies;

use App\Models\TrainRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TrainRoutePolicy extends NetworkPolicy
{
    /**
     * A route that trains still use can't be deleted.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof TrainRoute
            && ! $model->trains()->exists();
    }
}
