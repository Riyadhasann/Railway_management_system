<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class StationPolicy extends NetworkPolicy
{
    /**
     * A station that is used on a route can't be deleted (deactivate it instead).
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof Station
            && ! $model->routeStops()->exists();
    }
}
