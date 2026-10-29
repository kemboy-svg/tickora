<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform commission
    |--------------------------------------------------------------------------
    |
    | The booking fee added to each order and paid by the customer, in basis
    | points of the ticket subtotal after discounts (500 = 5.00%). Free
    | orders pay no fee. booking_fee_tax_bps is the tax charged on that fee
    | (e.g. 1500 for 15% VAT in Mauritius); ticket prices are tax-inclusive.
    | All amounts are calculated in minor units, rounding half away from
    | zero, never with floating point.
    |
    */

    'platform_fee_bps' => (int) env('TICKORA_PLATFORM_FEE_BPS', 500),

    'booking_fee_tax_bps' => (int) env('TICKORA_BOOKING_FEE_TAX_BPS', 0),

    /*
    |--------------------------------------------------------------------------
    | Inventory reservations
    |--------------------------------------------------------------------------
    |
    | How long an unpaid checkout holds its tickets before the reservation
    | expires and the inventory returns to sale.
    |
    */

    'reservation_ttl_minutes' => (int) env('TICKORA_RESERVATION_TTL_MINUTES', 10),

    // Once the customer moves on to payment the hold is extended to this,
    // so bank verification (3-D Secure) has time to finish.
    'payment_window_minutes' => (int) env('TICKORA_PAYMENT_WINDOW_MINUTES', 15),

    // Upper bound on tickets in one order, across all ticket types.
    'max_tickets_per_order' => (int) env('TICKORA_MAX_TICKETS_PER_ORDER', 20),

    // Calendar days on the platform dashboard are counted in this zone.
    'reporting_timezone' => env('TICKORA_REPORTING_TIMEZONE', 'Indian/Mauritius'),

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication for privileged areas
    |--------------------------------------------------------------------------
    |
    | When true, the admin area (and other routes using the "two-factor"
    | middleware) is closed until the user has confirmed two-factor
    | authentication. Always enforced in production; off by default
    | elsewhere so local development does not need an authenticator app.
    |
    */

    'require_two_factor' => env('APP_ENV') === 'production'
        ? true
        : (bool) env('TICKORA_REQUIRE_TWO_FACTOR', false),

    /*
    |--------------------------------------------------------------------------
    | Event images
    |--------------------------------------------------------------------------
    |
    | The filesystem disk event photos are stored on ("public" locally, after
    | `php artisan storage:link`; an S3-compatible disk in production) and
    | how many photos an event may have. Uploads are re-encoded, which strips
    | camera metadata such as GPS location, and scaled to at most
    | image_max_edge pixels on the long side.
    |
    */

    'media_disk' => env('TICKORA_MEDIA_DISK', 'public'),

    'max_event_images' => (int) env('TICKORA_MAX_EVENT_IMAGES', 8),

    'image_max_edge' => 2400,

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | ISO 4217 codes organisers may price events in. Every code listed here
    | must also exist in App\Support\Currency so its minor unit is known.
    | A currency being allowed here does not mean every payment provider
    | supports it; provider capability is checked separately.
    |
    */

    'currencies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TICKORA_CURRENCIES', 'MUR,KES,ZAR,USD,EUR')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | The adapter used for new checkouts, and per-provider settings. Only the
    | "fake" sandbox adapter exists until a real provider has been confirmed
    | for the platform's legal entity, countries and currencies.
    |
    */

    'payments' => [
        'default' => env('TICKORA_PAYMENT_PROVIDER', 'fake'),

        'providers' => [
            'fake' => [
                // Falls back to a secret derived from APP_KEY so the sandbox
                // works out of the box locally. Never used in production.
                'webhook_secret' => env('FAKE_PAYMENTS_WEBHOOK_SECRET')
                    ?: hash('sha256', 'tickora-fake-webhooks|'.env('APP_KEY')),
                'webhook_tolerance_seconds' => 300,
                // Send customers to the built-in sandbox payment screen.
                'hosted_page' => true,
                'currencies' => ['MUR', 'KES', 'ZAR', 'USD', 'EUR'],
            ],
        ],
    ],

];
