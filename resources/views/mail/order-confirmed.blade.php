<x-mail::message>
# You're going to {{ $event->title }}!

Hi {{ $order->customer_name ?? 'there' }}, your order is confirmed.

**When:** {{ $when }}
@if ($venue)

**Where:** {{ $venue }}
@endif

**Order:** {{ $order->reference }}

<x-mail::table>
| Ticket | Qty | Amount |
| :----- | :-: | -----: |
@foreach ($order->items as $item)
| {{ $item->ticket_name_snapshot }} | {{ $item->quantity }} | {{ \App\Support\Money::ofMinor($item->unit_amount_minor * $item->quantity, $order->currency)->format() }} |
@endforeach
@if ($order->discount_minor > 0)
| Discount | | −{{ $order->discount->format() }} |
@endif
| Booking fee | | {{ \App\Support\Money::ofMinor($order->platform_fee_minor + $order->tax_minor, $order->currency)->format() }} |
| **Total paid** | | **{{ $order->total->format() }}** |
</x-mail::table>

Your tickets, with their QR codes, are in your Tickora account. Show the QR code at the entrance; each one can be scanned once.

<x-mail::button :url="$url">
View my tickets
</x-mail::button>

See you there,<br>
{{ config('app.name') }}
</x-mail::message>
