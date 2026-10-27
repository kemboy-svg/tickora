<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\BelongsToOrganizer;
use App\Models\Concerns\HasPublicId;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Times are stored in UTC; `timezone` is the IANA zone used for display.
 */
#[Fillable([
    'category_id', 'venue_id', 'title', 'slug', 'summary', 'description', 'cover_image_key',
    'timezone', 'currency', 'starts_at', 'ends_at', 'sales_starts_at', 'sales_ends_at',
    'visibility', 'refund_policy', 'min_age',
])]
class Event extends Model
{
    use BelongsToOrganizer;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Status moves only through transitionTo().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'visibility' => 'public',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'visibility' => EventVisibility::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'min_age' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('sort_order');
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
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * Events anyone may see on listings: published, public, and not over.
     *
     * @param  Builder<static>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('status', EventStatus::Published)
            ->where('visibility', EventVisibility::Public)
            ->where('ends_at', '>', now());
    }

    /**
     * Events reachable on their public page (including unlisted ones).
     *
     * @param  Builder<static>  $query
     */
    public function scopePubliclyViewable(Builder $query): void
    {
        $query->whereIn('status', [EventStatus::Published, EventStatus::Completed]);
    }

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    /**
     * Move the event through its lifecycle, stamping the matching timestamp.
     * Callers are responsible for authorisation and the audit entry.
     */
    public function transitionTo(EventStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Event', $this->status, $to);
        }

        $this->status = $to;

        match ($to) {
            EventStatus::PendingReview => $this->submitted_at = now(),
            EventStatus::Published => $this->published_at = now(),
            EventStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };

        $this->save();
    }
}
