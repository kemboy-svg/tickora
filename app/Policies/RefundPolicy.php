<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    public function view(User $user, Refund $refund): bool
    {
        return $user->can('view', $refund->order);
    }

    /**
     * Platform finance approves refunds. Nobody approves a refund they
     * requested themselves (four-eyes principle).
     */
    public function approve(User $user, Refund $refund): bool
    {
        return $refund->status === RefundStatus::Requested
            && $refund->requested_by !== $user->id
            && $user->hasPlatformPermission(Permission::RefundsApprove);
    }

    public function reject(User $user, Refund $refund): bool
    {
        return $refund->status === RefundStatus::Requested
            && $user->hasPlatformPermission(Permission::RefundsApprove);
    }
}
