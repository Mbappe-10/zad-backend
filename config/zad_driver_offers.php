<?php

// Keep OFF on a live store until the new courier app has been installed and tested.
return [
    'enabled' => env('ZAD_DRIVER_OFFERS_ENABLED', false),
    'window_seconds' => max(5, min(120, (int) env('ZAD_DRIVER_OFFER_SECONDS', 20))),

    // Production-safe gradual rollout: OFF for all orders unless an order id is
    // explicitly allowlisted, or global rollout is separately approved.
    'test_order_ids' => array_values(array_filter(array_map(
        'intval', explode(',', (string) env('ZAD_DRIVER_OFFERS_TEST_ORDER_IDS', ''))
    ), fn (int $id) => $id > 0)),
    'all_orders' => env('ZAD_DRIVER_OFFERS_ALL_ORDERS', false),
];
