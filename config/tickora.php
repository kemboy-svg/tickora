<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform commission
    |--------------------------------------------------------------------------
    |
    | The platform fee charged on each order, in basis points of the ticket
    | subtotal (500 = 5.00%). Fees are calculated in minor units and rounded
    | half-up once per order line, never on floating point values.
    |
    */

    'platform_fee_bps' => (int) env('TICKORA_PLATFORM_FEE_BPS', 500),

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
                'webhook_secret' => env('FAKE_PAYMENTS_WEBHOOK_SECRET'),
                'webhook_tolerance_seconds' => 300,
                'currencies' => ['MUR', 'KES', 'ZAR', 'USD', 'EUR'],
            ],
        ],
    ],

];
