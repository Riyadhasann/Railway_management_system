<?php

namespace App\Policies;

use App\Models\Train;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TrainPolicy extends NetworkPolicy
{
    /**
     * A train with generated runs can't be deleted (deactivate it instead).
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof Train
            && ! $model->runs()->exists();
    }
}
