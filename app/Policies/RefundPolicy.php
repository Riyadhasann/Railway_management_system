<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\User;

/**
 * Refunds are created by RefundService only. Finance (payments.manage) may retry a failed one.
 */
class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ViewPayments->value)
            || $user->checkPermissionTo(Permission::ManagePayments->value);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Used for "Retry refund".
     */
    public function update(User $user, Refund $refund): bool
    {
        return $refund->status === RefundStatus::Failed
            && $user->checkPermissionTo(Permission::ManagePayments->value);
    }

    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
