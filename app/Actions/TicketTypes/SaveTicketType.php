<?php

namespace App\Actions\TicketTypes;

use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a ticket type. Its currency is always the event's.
 *
 * Once a ticket type has sold, its price is frozen (buyers already paid it,
 * and order items snapshot it anyway) and capacity can only be set at or
 * above what has been sold.
 */
class SaveTicketType
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, description: string|null, price: string, quantity_total: int, min_per_order: int, max_per_order: int, sales_starts_at: CarbonImmutable|null, sales_ends_at: CarbonImmutable|null, status: TicketTypeStatus}  $data
     */
    public function handle(User $actor, Event $event, array $data, ?TicketType $ticketType = null): TicketType
    {
        Gate::forUser($actor)->authorize('manageTicketTypes', $event);

        $price = Money::of($data['price'], $event->currency);

        if ($price->isNegative()) {
            throw ValidationException::withMessages(['price' => 'The price cannot be negative.']);
        }

        if ($data['sales_ends_at'] !== null && $data['sales_ends_at']->gt($event->ends_at)) {
            throw ValidationException::withMessages(['sales_ends_at' => 'Sales must end before the event does.']);
        }

        $duplicate = $event->ticketTypes()
            ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
            ->when($ticketType !== null, fn ($q) => $q->whereKeyNot($ticketType?->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'This event already has a ticket type with that name.']);
        }

        return DB::transaction(function () use ($actor, $event, $data, $price, $ticketType): TicketType {
            $creating = $ticketType === null;
            $ticketType ??= new TicketType;

            if (! $creating) {
                // Lock the row so capacity checks see sales committed meanwhile.
                $ticketType = TicketType::query()->lockForUpdate()->findOrFail($ticketType->id);

                if ($price->amount !== $ticketType->unit_amount_minor && $this->hasSold($ticketType)) {
                    throw ValidationException::withMessages([
                        'price' => 'Tickets of this type have been sold, so its price can no longer change. Create a new ticket type instead.',
                    ]);
                }

                if ($data['quantity_total'] < $ticketType->quantity_sold) {
                    throw ValidationException::withMessages([
                        'quantity_total' => "Capacity cannot go below the {$ticketType->quantity_sold} tickets already sold.",
                    ]);
                }
            }

            $before = $creating ? [] : [
                'unit_amount_minor' => $ticketType->unit_amount_minor,
                'quantity_total' => $ticketType->quantity_total,
                'status' => $ticketType->status->value,
            ];

            $ticketType->event_id = $event->id;
            $ticketType->name = $data['name'];
            $ticketType->description = $data['description'];
            $ticketType->currency = $event->currency;
            $ticketType->unit_amount_minor = $price->amount;
            $ticketType->quantity_total = $data['quantity_total'];
            $ticketType->min_per_order = $data['min_per_order'];
            $ticketType->max_per_order = $data['max_per_order'];
            $ticketType->sales_starts_at = $data['sales_starts_at'];
            $ticketType->sales_ends_at = $data['sales_ends_at'];
            $ticketType->status = $data['status'];

            if ($creating) {
                $ticketType->sort_order = (int) $event->ticketTypes()->max('sort_order') + 1;
            }

            $ticketType->save();

            $this->audit->record($creating ? 'ticket_type.created' : 'ticket_type.updated', $ticketType, [
                'event_id' => $event->id,
                'before' => $before,
                'after' => [
                    'unit_amount_minor' => $ticketType->unit_amount_minor,
                    'quantity_total' => $ticketType->quantity_total,
                    'status' => $ticketType->status->value,
                ],
            ], actor: $actor, organizer: $event->organizer);

            return $ticketType;
        });
    }

    public function delete(User $actor, Event $event, TicketType $ticketType): void
    {
        Gate::forUser($actor)->authorize('manageTicketTypes', $event);

        if ($this->hasSold($ticketType) || $ticketType->reservations()->exists()) {
            throw ValidationException::withMessages([
                'ticket_type' => 'This ticket type has sales or reservations, so it cannot be deleted. Pause or hide it instead.',
            ]);
        }

        DB::transaction(function () use ($actor, $event, $ticketType): void {
            $this->audit->record('ticket_type.deleted', $ticketType, [
                'event_id' => $event->id,
                'name' => $ticketType->name,
            ], actor: $actor, organizer: $event->organizer);

            $ticketType->delete();
        });
    }

    private function hasSold(TicketType $ticketType): bool
    {
        return $ticketType->quantity_sold > 0
            || OrderItem::query()->where('ticket_type_id', $ticketType->id)->exists();
    }
}
