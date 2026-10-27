<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Enums\Permission;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserStatus $status
 * @property PlatformRole|null $platform_role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Status and platform_role are deliberately not fillable: they change
     * only through audited admin actions, never through mass assignment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'platform_role' => PlatformRole::class,
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrganizerMember, $this>
     */
    public function organizerMemberships(): HasMany
    {
        return $this->hasMany(OrganizerMember::class);
    }

    /**
     * Organisers the user is an active member of.
     *
     * @return BelongsToMany<Organizer, $this>
     */
    public function organizers(): BelongsToMany
    {
        return $this->belongsToMany(Organizer::class, 'organizer_members')
            ->withPivot(['role', 'status'])
            ->wherePivot('status', MembershipStatus::Active->value)
            ->withTimestamps();
    }

    /**
     * @return HasMany<EventStaffAssignment, $this>
     */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(EventStaffAssignment::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Tickets this user holds (bought or transferred to them).
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'holder_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * The user's active membership of an organiser, if any.
     */
    public function membershipFor(Organizer $organizer): ?OrganizerMember
    {
        if ($this->relationLoaded('organizerMemberships')) {
            return $this->organizerMemberships->first(
                fn (OrganizerMember $m): bool => $m->organizer_id === $organizer->id && $m->isActive(),
            );
        }

        return $this->organizerMemberships()
            ->where('organizer_id', $organizer->id)
            ->where('status', MembershipStatus::Active)
            ->first();
    }

    public function roleIn(Organizer $organizer): ?OrganizerRole
    {
        return $this->membershipFor($organizer)?->role;
    }

    /**
     * Whether the user may do something inside an organiser's workspace.
     * Suspended users and rejected organisers get nothing.
     */
    public function hasOrganizerPermission(Organizer $organizer, Permission $permission): bool
    {
        if (! $this->isActive() || ! $organizer->status->workspaceAccessible()) {
            return false;
        }

        return $this->roleIn($organizer)?->can($permission) ?? false;
    }

    public function hasPlatformPermission(Permission $permission): bool
    {
        return $this->isActive() && ($this->platform_role?->can($permission) ?? false);
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    /**
     * Holds a current (not revoked) staff assignment for the event.
     */
    public function isAssignedToEvent(Event $event): bool
    {
        return $this->staffAssignments()
            ->where('event_id', $event->id)
            ->whereNull('revoked_at')
            ->exists();
    }
}
