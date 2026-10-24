<?php
return [
    // Opt-in only. This does not authorize any provider refund, payment, or SMS.
    'enabled' => (bool) env('ZAD_V4_FAMILY_ACTIONS_ENABLED', false),
];
