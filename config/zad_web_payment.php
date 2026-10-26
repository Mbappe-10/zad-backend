<?php

return [
    'enabled' => (bool) env('ZAD_WEB_INTENTS_ENABLED', false),
    // All three pilot values are server-only. Pilot mode is ON by default.
    // Missing order ID blocks every new web intent, even if the global switch is ON.
    'pilot_enabled' => (bool) env('ZAD_WEB_PILOT_ENABLED', true),
    'pilot_order_id' => (int) env('ZAD_WEB_PILOT_ORDER_ID', 0),
    'pilot_max_halalas' => (int) env('ZAD_WEB_PILOT_MAX_HALALAS', 2000),
    'callback_url' => env(
        'ZAD_WEB_MOYASAR_CALLBACK_URL',
        'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
    ),
];
