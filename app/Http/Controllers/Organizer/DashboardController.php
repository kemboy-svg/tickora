<?php

namespace App\Http\Controllers\Organizer;

use App\Enums\EventStatus;
use App\Enums\OrganizerStatus;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Reports\SalesReport;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales overview for the current workspace. Needs the reports permission
 * (owners and managers); gate staff are sent to their events instead.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesReport $report): Response|RedirectResponse
    {
        $organizer = $this->workspace($request);
        $user = $this->actor($request);

        if (! Gate::allows('viewReports', $organizer)) {
            return to_route('organizer.events.index');
        }

        $timezone = LocalTime::defaultZoneFor($organizer->country_code);
        $since = CarbonImmutable::now()->subDays(30);

        $events = Event::query()
            ->forOrganizer($organizer)
            ->whereIn('status', [EventStatus::Draft, EventStatus::PendingReview, EventStatus::Published])
            ->where('ends_at', '>', now())
            ->with(['venue', 'ticketTypes'])
            ->orderBy('starts_at')
            ->limit(8)
            ->get();

        $revenue = DB::table('orders')
            ->whereIn('status', SalesReport::soldStatuses())
            ->whereIn('event_id', $events->pluck('id'))
            ->groupBy('event_id')
            ->selectRaw('event_id, sum(subtotal_minor - discount_minor) as sales')
            ->pluck('sales', 'event_id');

        $canSeeOrders = $user->hasOrganizerPermission($organizer, Permission::OrdersView);

        return Inertia::render('organizer/Dashboard', [
            'organizer' => [
                'name' => $organizer->name,
                'status' => $organizer->status->value,
                'can_sell' => $organizer->canSell(),
                'default_currency' => $organizer->default_currency,
            ],
            'timezone' => $timezone,
            'totals' => [
                'all_time' => $report->totals($organizer),
                'last_30_days' => $report->totals($organizer, $since),
            ],
            'tickets' => [
                'all_time' => $report->ticketsSold($organizer),
                'last_30_days' => $report->ticketsSold($organizer, $since),
            ],
            'orders_last_30_days' => $report->orderCount($organizer, $since),
            'daily' => $report->ticketsPerDay($organizer, 30, $timezone),
            'events' => $events->map(fn (Event $event): array => [
                'id' => $event->public_id,
                'title' => $event->title,
                'status' => $event->status->value,
                'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M · H:i'),
                'venue' => $event->venue?->name,
                'sold' => (int) $event->ticketTypes->sum('quantity_sold'),
                'capacity' => (int) $event->ticketTypes->sum('quantity_total'),
                'ticket_types' => $event->ticketTypes->count(),
                'sales' => Money::ofMinor((int) ($revenue[$event->id] ?? 0), $event->currency),
            ])->values()->all(),
            'attention' => $this->attention($organizer->status, $organizer->id),
            'recent_orders' => $canSeeOrders ? $this->recentOrders($organizer->id) : null,
        ]);
    }

    /**
     * Things the organiser should act on, in plain language.
     *
     * @return list<array{tone: string, message: string, href: string|null}>
     */
    private function attention(OrganizerStatus $status, int $organizerId): array
    {
        $items = [];

        if ($status === OrganizerStatus::Pending) {
            $items[] = ['tone' => 'info', 'message' => 'Your organiser profile is waiting for verification. You can prepare events, but they can only be published once you are verified.', 'href' => null];
        } elseif ($status !== OrganizerStatus::Verified) {
            $items[] = ['tone' => 'warning', 'message' => 'Your organiser account is '.$status->value.'. Contact support to sell tickets.', 'href' => null];
        }

        $returned = Event::query()
            ->where('organizer_id', $organizerId)
            ->where('status', EventStatus::Draft)
            ->whereNotNull('review_notes')
            ->count();

        if ($returned > 0) {
            $items[] = ['tone' => 'warning', 'message' => $returned === 1 ? '1 event was sent back by review with notes.' : "{$returned} events were sent back by review with notes.", 'href' => '/organizer/events?status=draft'];
        }

        $inReview = Event::query()->where('organizer_id', $organizerId)->where('status', EventStatus::PendingReview)->count();

        if ($inReview > 0) {
            $items[] = ['tone' => 'info', 'message' => $inReview === 1 ? '1 event is waiting for review.' : "{$inReview} events are waiting for review.", 'href' => '/organizer/events?status=pending_review'];
        }

        $almostGone = TicketType::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId)->where('status', EventStatus::Published)->where('ends_at', '>', now()))
            // At least 90% sold, in integer arithmetic so it works on every database.
            ->whereRaw('quantity_total > 0 and quantity_sold * 10 >= quantity_total * 9 and quantity_sold < quantity_total')
            ->count();

        if ($almostGone > 0) {
            $items[] = ['tone' => 'good', 'message' => $almostGone === 1 ? '1 ticket type is over 90% sold. Consider adding capacity or a new tier.' : "{$almostGone} ticket types are over 90% sold. Consider adding capacity or a new tier.", 'href' => null];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(int $organizerId): array
    {
        return array_values(Order::query()
            ->where('organizer_id', $organizerId)
            ->whereIn('status', SalesReport::soldStatuses())
            ->with('event:id,title')
            ->withSum('items', 'quantity')
            ->latest('paid_at')
            ->limit(8)
            ->get()
            ->map(fn (Order $order): array => [
                'reference' => $order->reference,
                'customer' => $order->customer_name ?? 'Customer',
                'event' => $order->event->title,
                'tickets' => (int) ($order->getAttribute('items_sum_quantity') ?? 0),
                // The organiser's share: ticket sales after discounts, without the booking fee.
                'amount' => Money::ofMinor($order->subtotal_minor - $order->discount_minor, $order->currency),
                'status' => $order->status->value,
                'paid_label' => $order->paid_at?->diffForHumans(),
            ])
            ->all());
    }
}
