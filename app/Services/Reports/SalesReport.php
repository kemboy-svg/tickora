<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Organizer;
use App\Support\Money;
use App\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures for the dashboards, read straight from orders and order
 * items. Platform-wide when no organiser is given, otherwise scoped to that
 * organiser only.
 *
 * "Sold" means orders that are paid or partially refunded. Fully refunded,
 * cancelled and unpaid orders are not sales. Amounts are never summed
 * across currencies; every money figure is per currency.
 */
class SalesReport
{
    /**
     * @return list<string>
     */
    public static function soldStatuses(): array
    {
        return [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value];
    }

    private function orders(?Organizer $organizer, ?CarbonImmutable $since = null): Builder
    {
        return DB::table('orders')
            ->whereIn('orders.status', self::soldStatuses())
            ->when($organizer !== null, fn (Builder $q) => $q->where('orders.organizer_id', $organizer?->id))
            ->when($since !== null, fn (Builder $q) => $q->where('orders.paid_at', '>=', $since));
    }

    /**
     * Totals per currency, largest ticket sales first.
     *
     * @return list<array{currency: string, ticket_sales: Money, gross: Money, fees: Money, refunded: Money, orders: int}>
     */
    public function totals(?Organizer $organizer = null, ?CarbonImmutable $since = null): array
    {
        $rows = $this->orders($organizer, $since)
            ->groupBy('orders.currency')
            ->selectRaw('orders.currency,
                sum(orders.subtotal_minor - orders.discount_minor) as ticket_sales,
                sum(orders.total_minor) as gross,
                sum(orders.platform_fee_minor + orders.tax_minor) as fees,
                sum(orders.refunded_minor) as refunded,
                count(*) as orders')
            ->orderByRaw('sum(orders.subtotal_minor - orders.discount_minor) desc')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $totals[] = [
                'currency' => $currency,
                'ticket_sales' => Money::ofMinor((int) $row->ticket_sales, $currency),
                'gross' => Money::ofMinor((int) $row->gross, $currency),
                'fees' => Money::ofMinor((int) $row->fees, $currency),
                'refunded' => Money::ofMinor((int) $row->refunded, $currency),
                'orders' => (int) $row->orders,
            ];
        }

        return $totals;
    }

    public function ticketsSold(?Organizer $organizer = null, ?CarbonImmutable $since = null): int
    {
        return (int) $this->orders($organizer, $since)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->sum('order_items.quantity');
    }

    public function orderCount(?Organizer $organizer = null, ?CarbonImmutable $since = null): int
    {
        return $this->orders($organizer, $since)->count();
    }

    /**
     * Tickets sold per calendar day in the given timezone, oldest first,
     * with zero-filled days so the series has no gaps.
     *
     * @return list<array{date: string, label: string, tickets: int, orders: int}>
     */
    public function ticketsPerDay(?Organizer $organizer, int $days, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $first = $today->subDays($days - 1);

        [$day, $bindings] = Sql::localDate('orders.paid_at', $timezone);

        $rows = $this->orders($organizer, $first->utc())
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->groupBy('day')
            ->addSelect(new Expression($day.' as day'))
            ->addBinding($bindings, 'select')
            ->selectRaw('sum(order_items.quantity) as tickets')
            ->selectRaw('count(distinct orders.id) as orders')
            ->get()
            ->keyBy('day');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $first->addDays($i);
            $key = $day->format('Y-m-d');
            $row = $rows->get($key);

            $series[] = [
                'date' => $key,
                'label' => $day->format('D j M'),
                'tickets' => $row !== null ? (int) $row->tickets : 0,
                'orders' => $row !== null ? (int) $row->orders : 0,
            ];
        }

        return $series;
    }
}
