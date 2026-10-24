<?php

return [
    'api_key' => env('TAWKED_API_KEY', ''),
    'base_url' => env('TAWKED_BASE_URL', 'https://tawked.com'),
    'expected_mode' => env('TAWKED_EXPECTED_MODE', 'test'),
    'four_digits_confirmed' => filter_var(env('TAWKED_FOUR_DIGITS_CONFIRMED', false), FILTER_VALIDATE_BOOL),
    'timeout_seconds' => (int) env('TAWKED_TIMEOUT_SECONDS', 10),
];
