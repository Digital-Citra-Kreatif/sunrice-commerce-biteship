<?php

declare(strict_types=1);

return [
    /*
    | The API key from Biteship's dashboard (Integrations → API). A key
    | starting "biteship_test." is Testing Mode: orders are simulated (no
    | courier comes, nothing is charged), rates and tracking are real data.
    */
    'api_key' => env('BITESHIP_API_KEY'),

    'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),

    'timeout' => (int) env('BITESHIP_TIMEOUT', 15),

    /*
    | Where the courier picks parcels up. Empty values use the store address
    | (Settings → Store → General). Instant couriers (GoSend, GrabExpress,
    | Lalamove, Borzo, Paxel) need the coordinates of both ends.
    */
    'origin' => [
        'postal_code' => env('BITESHIP_ORIGIN_POSTAL_CODE'),
        'latitude' => env('BITESHIP_ORIGIN_LATITUDE'),
        'longitude' => env('BITESHIP_ORIGIN_LONGITUDE'),
        'contact_name' => env('BITESHIP_ORIGIN_CONTACT_NAME'),
        'contact_phone' => env('BITESHIP_ORIGIN_CONTACT_PHONE'),
        'note' => env('BITESHIP_ORIGIN_NOTE'),
    ],

    /*
    | Biteship calls this path (under the storefront) when a booking changes.
    | Add it in Biteship's dashboard: Integrations → Webhook, events
    | order.status, order.price and order.waybill_id.
    */
    'webhook_path' => env('BITESHIP_WEBHOOK_PATH', 'biteship/webhook'),

    // The queue for automatic bookings (null: the default queue).
    'queue' => env('BITESHIP_QUEUE'),
];
