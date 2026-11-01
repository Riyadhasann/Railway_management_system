<?php

namespace App\Policies;

use App\Models\CoachType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CoachTypePolicy extends NetworkPolicy
{
    /**
     * A coach type used in any train's composition can't be deleted.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user)
            && $model instanceof CoachType
            && ! $model->rakeCoaches()->exists();
    }
}
