<?php

namespace App\Policies;

use App\Models\Concession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ConcessionPolicy extends FarePolicy
{
    /**
     * A concession given to any passenger can't be deleted (deactivate it instead).
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof Concession
            && ! $model->passengers()->exists();
    }
}
