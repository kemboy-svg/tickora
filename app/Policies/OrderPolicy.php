<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * The customer who placed it, the organiser's sales team, or platform
     * finance/support staff.
     */
    public function view(User $user, Order $order): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($order->belongsToCustomer($user)) {
            return true;
        }

        if ($user->hasPlatformPermission(Permission::PaymentsView)) {
            return true;
        }

        return $user->hasOrganizerPermission($order->organizer, Permission::OrdersView);
    }

    /**
     * Ask for a refund. Approval and execution are separate platform steps
     * (RefundPolicy::approve), so requesting never moves money by itself.
     */
    public function requestRefund(User $user, Order $order): bool
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            return false;
        }

        if ($user->isActive() && $order->belongsToCustomer($user)) {
            return true;
        }

        return $user->hasOrganizerPermission($order->organizer, Permission::RefundsRequest);
    }
}
