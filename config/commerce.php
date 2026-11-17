<?php

return [
    'operations_enabled' => env('COMMERCE_OPERATIONS_ENABLED', false),
    'enabled' => env('COMMERCE_WORKSPACES_ENABLED', false),
    'payments_enabled' => env('COMMERCE_PAYMENTS_ENABLED', false),
    'live_gateways' => env('COMMERCE_LIVE_GATEWAYS', false),
    'stripe' => [
        'secret' => env('COMMERCE_STRIPE_SECRET'),
        'webhook_secret' => env('COMMERCE_STRIPE_WEBHOOK_SECRET'),
    ],
    'paypal' => [
        'client_id' => env('COMMERCE_PAYPAL_CLIENT_ID'),
        'secret' => env('COMMERCE_PAYPAL_SECRET'),
        'merchant_id' => env('COMMERCE_PAYPAL_MERCHANT_ID'),
        'webhook_id' => env('COMMERCE_PAYPAL_WEBHOOK_ID'),
    ],
];
