<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventStatus;
use App\Enums\OrganizerStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform overview for staff. Money figures need the payments permission
 * (admin, finance, support); the work queues are shown to whoever can act
 * on them.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SalesReport $report, Config $config): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isPlatformStaff() && $user->isActive(), 403);

        $timezone = $config->get('tickora.reporting_timezone', 'Indian/Mauritius');
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
        $since = CarbonImmutable::now()->subDays(30);
        $canSeeMoney = $user->hasPlatformPermission(Permission::PaymentsView);

        return Inertia::render('admin/Dashboard', [
            'timezone' => $timezone,
            'can' => [
                'money' => $canSeeMoney,
                'moderate' => $user->hasPlatformPermission(Permission::EventsModerate),
                'verify' => $user->hasPlatformPermission(Permission::OrganizersVerify),
            ],
            'totals' => $canSeeMoney ? [
                'all_time' => $report->totals(null),
                'last_30_days' => $report->totals(null, $since),
            ] : null,
            'tickets' => [
                'all_time' => $report->ticketsSold(),
                'last_30_days' => $report->ticketsSold(null, $since),
            ],
            'orders' => [
                'all_time' => $report->orderCount(),
                'last_30_days' => $report->orderCount(null, $since),
            ],
            'users' => [
                'total' => User::query()->count(),
                'last_30_days' => User::query()->where('created_at', '>=', $since)->count(),
            ],
            'queues' => [
                'events_in_review' => Event::query()->where('status', EventStatus::PendingReview)->count(),
                'organizers_pending' => Organizer::query()->where('status', OrganizerStatus::Pending)->count(),
            ],
            'organizers_by_status' => $this->countBy('organizers', array_map(fn (OrganizerStatus $s): string => $s->value, OrganizerStatus::cases())),
            'events_by_status' => $this->countBy('events', array_map(fn (EventStatus $s): string => $s->value, EventStatus::cases())),
            'daily' => $report->ticketsPerDay(null, 30, $timezone),
            'top_organizers' => $this->topOrganizers($since),
            'recent_orders' => $canSeeMoney ? $this->recentOrders() : null,
        ]);
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function countBy(string $table, array $statuses): array
    {
        $counts = DB::table($table)->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        $result = [];
        foreach ($statuses as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * Organisers by tickets sold in the period.
     *
     * @return list<array{name: string, slug: string, tickets: int, orders: int}>
     */
    private function topOrganizers(CarbonImmutable $since): array
    {
        $rows = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('organizers', 'organizers.id', '=', 'orders.organizer_id')
            ->whereIn('orders.status', SalesReport::soldStatuses())
            ->where('orders.paid_at', '>=', $since)
            ->groupBy('organizers.id', 'organizers.name', 'organizers.slug')
            ->selectRaw('organizers.name, organizers.slug, sum(order_items.quantity) as tickets, count(distinct orders.id) as orders')
            ->orderByDesc('tickets')
            ->limit(5)
            ->get();

        $top = [];
        foreach ($rows as $row) {
            $top[] = [
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'tickets' => (int) $row->tickets,
                'orders' => (int) $row->orders,
            ];
        }

        return $top;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return array_values(Order::query()
            ->whereIn('status', SalesReport::soldStatuses())
            ->with(['event:id,title', 'organizer:id,name'])
            ->withSum('items', 'quantity')
            ->latest('paid_at')
            ->limit(8)
            ->get()
            ->map(fn (Order $order): array => [
                'reference' => $order->reference,
                'organizer' => $order->organizer->name,
                'event' => $order->event->title,
                'tickets' => (int) ($order->getAttribute('items_sum_quantity') ?? 0),
                'total' => $order->total,
                'fee' => $order->platform_fee,
                'paid_label' => $order->paid_at?->diffForHumans(),
            ])
            ->all());
    }
}
