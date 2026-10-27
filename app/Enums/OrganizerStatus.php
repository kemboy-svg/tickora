<?php

namespace App\Enums;

enum OrganizerStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * Only verified organisers may have events published and sell tickets.
     */
    public function canSell(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether members can still sign in to the organiser workspace (to view
     * history, export data or resolve issues) even if selling is blocked.
     */
    public function workspaceAccessible(): bool
    {
        return $this !== self::Rejected;
    }
}
