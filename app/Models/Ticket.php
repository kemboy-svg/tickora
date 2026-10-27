<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\HasPublicId;
use App\Support\TicketToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admission. The QR code carries a random token whose SHA-256 hash is
 * qr_token_hash; the raw token is never stored. Tickets are issued only by
 * the IssueTickets action after confirmed payment, so nothing is fillable.
 */
class Ticket extends Model
{
    use HasPublicId;

    /**
     * @var list<string>
     */
    protected $hidden = ['qr_token_hash'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'valid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'issued_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * Find the ticket a scanned QR payload refers to, if any.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForQrPayload(Builder $query, string $payload): void
    {
        $token = TicketToken::fromPayload($payload);

        if ($token === null) {
            // An unparseable payload must match nothing, not everything.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('qr_token_hash', $token->hash());
    }

    /**
     * Replace the QR token (lost ticket, suspected sharing). The old code
     * stops working immediately. Returns the new token so its payload can be
     * shown or emailed once; it is not retrievable afterwards.
     */
    public function rotateToken(): TicketToken
    {
        $token = TicketToken::issue();
        $this->qr_token_hash = $token->hash();
        $this->save();

        return $token;
    }

    public function transitionTo(TicketStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Ticket', $this->status, $to);
        }

        $this->status = $to;

        match ($to) {
            TicketStatus::CheckedIn => $this->checked_in_at = now(),
            TicketStatus::Valid => $this->checked_in_at = null,
            TicketStatus::Void => $this->voided_at = now(),
            default => null,
        };

        $this->save();
    }
}
