<?php

use App\Http\Controllers\Api\App\MoyasarPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/app')->group(function (): void {
    Route::post(
        'orders/{order}/payment/attempt',
        [MoyasarPaymentController::class, 'attempt'],
    )->middleware('throttle:20,1');

    Route::post(
        'orders/{order}/payment/verify',
        [MoyasarPaymentController::class, 'verify'],
    )->middleware('throttle:20,1');

    Route::get(
        'orders/{order}/payment/status',
        [MoyasarPaymentController::class, 'status'],
    )->middleware('throttle:30,1');

    Route::post(
        'orders/{order}/payment/local-test',
        [MoyasarPaymentController::class, 'localTest'],
    )->middleware('throttle:5,1');
});

Route::post('v1/payments/moyasar/webhook', [MoyasarPaymentController::class, 'webhook']);
