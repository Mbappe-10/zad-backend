<?php

use App\Http\Controllers\Api\App\MoyasarPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/app')->group(function (): void {
    Route::post(
        'orders/{order}/payment/attempt',
        [MoyasarPaymentController::class, 'attempt'],
    )->middleware('throttle:payment-action');

    Route::post(
        'orders/{order}/payment/verify',
        [MoyasarPaymentController::class, 'verify'],
    )->middleware('throttle:payment-action');

    Route::get(
        'orders/{order}/payment/status',
        [MoyasarPaymentController::class, 'status'],
    )->middleware('throttle:payment-status');

    Route::post(
        'orders/{order}/payment/local-test',
        [MoyasarPaymentController::class, 'localTest'],
    )->middleware('throttle:payment-action');
});

Route::post('v1/payments/moyasar/webhook', [MoyasarPaymentController::class, 'webhook']);
