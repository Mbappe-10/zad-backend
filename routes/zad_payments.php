<?php

use App\Http\Controllers\Api\App\MoyasarPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/app')->group(function (): void {
    Route::post('orders/{order}/payment/attempt', [MoyasarPaymentController::class, 'attempt']);
    Route::post('orders/{order}/payment/verify', [MoyasarPaymentController::class, 'verify']);
    Route::get('orders/{order}/payment/status', [MoyasarPaymentController::class, 'status']);
    Route::post('orders/{order}/payment/local-test', [MoyasarPaymentController::class, 'localTest']);
});

Route::post('v1/payments/moyasar/webhook', [MoyasarPaymentController::class, 'webhook']);