<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\LocalTime;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once an order is paid. Links to the order page, where the tickets'
 * QR codes are shown to the signed-in customer; the email itself carries
 * no QR secrets, so a forwarded email cannot be used to get in.
 */
class OrderConfirmed extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your tickets for {$this->order->event->title} ({$this->order->reference})",
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing(['event.venue', 'items']);
        $event = $order->event;

        return new Content(
            markdown: 'mail.order-confirmed',
            with: [
                'order' => $order,
                'event' => $event,
                'when' => LocalTime::display($event->starts_at, $event->timezone, 'l j F Y, H:i')
                    .' ('.LocalTime::offsetLabel($event->starts_at, $event->timezone).')',
                'venue' => $event->venue === null ? null : $event->venue->name.', '.$event->venue->city,
                'url' => route('orders.show', $order),
            ],
        );
    }
}
