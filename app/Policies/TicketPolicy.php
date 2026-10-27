<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * A ticket's QR code is a bearer credential, so only its holder and the
 * customer who bought it may open or download it. Organisers see attendee
 * details through attendee lists, never the QR code itself.
 */
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($ticket->holder_user_id !== null && $ticket->holder_user_id === $user->id) {
            return true;
        }

        return $ticket->order->belongsToCustomer($user);
    }

    public function download(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
