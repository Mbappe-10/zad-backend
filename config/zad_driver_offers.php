<?php

// Keep OFF on a live store until the new courier app has been installed and tested.
return [
    'enabled' => env('ZAD_DRIVER_OFFERS_ENABLED', false),
    'window_seconds' => max(5, min(120, (int) env('ZAD_DRIVER_OFFER_SECONDS', 20))),
];
