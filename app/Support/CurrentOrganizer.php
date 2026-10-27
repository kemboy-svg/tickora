<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which organiser workspace the signed-in user is working in. Users can
 * belong to several organisers; the choice is kept in the session and is
 * always re-validated against their active memberships, so a stale or
 * tampered session value can never select a foreign organiser.
 */
class CurrentOrganizer
{
    public const SESSION_KEY = 'tickora.current_organizer';

    public function __construct(private readonly Session $session) {}

    /**
     * Organisers the user can work in, alphabetically.
     *
     * @return Collection<int, Organizer>
     */
    public function available(User $user): Collection
    {
        return Organizer::query()
            ->whereIn('id', $user->organizerMemberships()
                ->where('status', MembershipStatus::Active)
                ->select('organizer_id'))
            ->get()
            ->filter(fn (Organizer $organizer): bool => $organizer->status->workspaceAccessible())
            ->sortBy('name')
            ->values();
    }

    public function resolve(User $user): ?Organizer
    {
        $available = $this->available($user);
        $selected = $this->session->get(self::SESSION_KEY);

        $organizer = is_string($selected)
            ? $available->firstWhere('public_id', $selected)
            : null;

        $organizer ??= $available->first();

        if ($organizer !== null) {
            $this->session->put(self::SESSION_KEY, $organizer->public_id);
        }

        return $organizer;
    }

    /**
     * Switch workspaces. Returns false if the user is not an active member.
     */
    public function switchTo(User $user, string $publicId): bool
    {
        $organizer = $this->available($user)->firstWhere('public_id', $publicId);

        if ($organizer === null) {
            return false;
        }

        $this->session->put(self::SESSION_KEY, $organizer->public_id);

        return true;
    }
}
