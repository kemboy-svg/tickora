<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use Database\Factories\OrganizerMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership of an organiser. Created and changed only through the
 * Team actions, which enforce owner rules and write the audit trail, so no
 * attribute is mass assignable.
 */
class OrganizerMember extends Model
{
    /** @use HasFactory<OrganizerMemberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizerRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organizer, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
