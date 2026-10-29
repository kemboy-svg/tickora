<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\HasPublicId;
use App\Support\TicketToken;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One admission. The QR code carries a random token whose SHA-256 hash is
 * qr_token_hash; the raw token is never stored. Tickets are issued only by
 * the IssueTickets action after confirmed payment, so nothing is fillable.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $order_id
 * @property int $order_item_id
 * @property int $event_id
 * @property int $ticket_type_id
 * @property int|null $holder_user_id
 * @property string|null $attendee_name
 * @property string|null $attendee_email
 * @property string $qr_token_hash
 * @property string|null $qr_token_ciphertext
 * @property TicketStatus $status
 * @property CarbonInterface $issued_at
 * @property CarbonInterface|null $checked_in_at
 * @property CarbonInterface|null $voided_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Ticket extends Model
{
    use HasPublicId;

    /**
     * @var list<string>
     */
    protected $hidden = ['qr_token_hash', 'qr_token_ciphertext'];

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
        $this->useToken($token);
        $this->save();

        return $token;
    }

    /**
     * Store a token: its hash for scanning, and an encrypted copy so the
     * holder's ticket page can show the QR code again.
     */
    public function useToken(TicketToken $token): void
    {
        $this->qr_token_hash = $token->hash();
        $this->qr_token_ciphertext = Crypt::encryptString($token->raw);
    }

    /**
     * The QR payload for the holder's ticket page, or null if it cannot be
     * recovered (issued before encryption was added, or the key changed).
     */
    public function qrPayload(): ?string
    {
        if ($this->qr_token_ciphertext === null) {
            return null;
        }

        try {
            $raw = Crypt::decryptString($this->qr_token_ciphertext);
        } catch (DecryptException) {
            return null;
        }

        $token = TicketToken::fromRaw($raw);

        return $token !== null && hash_equals($this->qr_token_hash, $token->hash()) ? $token->payload() : null;
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
