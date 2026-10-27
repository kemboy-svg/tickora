<?php

namespace App\Policies;

use App\Models\Checkout;
use App\Models\User;

/**
 * A checkout belongs to the customer who started it and nobody else —
 * not organisers, not platform staff.
 */
class CheckoutPolicy
{
    public function view(User $user, Checkout $checkout): bool
    {
        return $checkout->belongsToCustomer($user);
    }

    public function update(User $user, Checkout $checkout): bool
    {
        return $checkout->belongsToCustomer($user);
    }
}
