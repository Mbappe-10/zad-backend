<?php

return [
    'enabled' => (bool) env('ZAD_WEB_INTENTS_ENABLED', false),
    'callback_url' => env(
        'ZAD_WEB_MOYASAR_CALLBACK_URL',
        'https://zad-sync-sa.onrender.com/zad-moyasar-return.html',
    ),
];
