<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerStatus;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Database\Factories\OrganizerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant: a company or person selling tickets on Tickora.
 *
 * @property int $id
 * @property string $public_id
 * @property int $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $country_code
 * @property string $default_currency
 * @property string|null $payout_currency
 * @property OrganizerStatus $status
 * @property string|null $support_email
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'country_code', 'default_currency', 'support_email'])]
class Organizer extends Model
{
    /** @use HasFactory<OrganizerFactory> */
    use HasFactory, HasPublicId;

    /**
     * Status, ownership and payout currency change only through dedicated,
     * audited actions — never mass assignment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizerStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<OrganizerMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizerMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organizer_members')
            ->withPivot(['role', 'status'])
            ->wherePivot('status', MembershipStatus::Active->value)
            ->withTimestamps();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Venue, $this>
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    /**
     * @return HasMany<OrganizerVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(OrganizerVerification::class);
    }

    /**
     * @return HasMany<PromoCode, $this>
     */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<OrganizerPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(OrganizerPayout::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function canSell(): bool
    {
        return $this->status->canSell();
    }
}
